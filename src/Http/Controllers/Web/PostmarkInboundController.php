<?php

namespace AxelFerdinand\StatamicSecretary\Http\Controllers\Web;

use AxelFerdinand\StatamicSecretary\Data\InboundAttachment;
use AxelFerdinand\StatamicSecretary\Data\InboundEmail;
use AxelFerdinand\StatamicSecretary\Email\EmailConfiguration;
use AxelFerdinand\StatamicSecretary\Email\InboundEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class PostmarkInboundController extends Controller
{
    public function __construct(
        private readonly InboundEmailService $inbound,
        private readonly EmailConfiguration $email,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->email->enabled(), 403, 'Secretary inbound email is disabled.');
        abort_unless($this->email->emailAddressesAreUsable(), 503, 'Secretary email addresses are not configured for threaded replies.');
        $this->verifyBasicAuthentication($request);
        $maximumBytes = max(1024, (int) config('secretary.limits.max_webhook_bytes', 24_000_000));
        $maximumAttachments = max(1, min((int) config('secretary.assets.max_attachments', 4), 10));
        abort_if(strlen($request->getContent()) > $maximumBytes, 403, 'The Postmark inbound payload is too large.');
        $validator = Validator::make($request->all(), [
            'MessageID' => ['required', 'string', 'max:255'],
            'MailboxHash' => ['nullable', 'string', 'max:64'],
            'Subject' => ['nullable', 'string', 'max:998'],
            'TextBody' => ['nullable', 'string'],
            'StrippedTextReply' => ['nullable', 'string'],
            'HtmlBody' => ['nullable', 'string'],
            'FromFull.Email' => ['required', 'email:rfc', 'max:255'],
            'Headers' => ['nullable', 'array', 'max:200'],
            'Headers.*.Name' => ['required_with:Headers', 'string', 'max:255'],
            'Headers.*.Value' => ['nullable', 'string', 'max:10000'],
            'Attachments' => ['nullable', 'array', "max:{$maximumAttachments}"],
            'Attachments.*.Name' => ['required_with:Attachments', 'string', 'max:255'],
            'Attachments.*.ContentType' => ['required_with:Attachments', 'string', 'max:100'],
            'Attachments.*.Content' => ['required_with:Attachments', 'string'],
            'Attachments.*.ContentLength' => ['required_with:Attachments', 'integer', 'min:1'],
        ]);
        abort_if($validator->fails(), 403, 'Invalid Postmark inbound payload.');
        $payload = $validator->validated();
        $sender = (string) data_get($payload, 'FromFull.Email');

        if ($this->email->senderIsOwnAddress($sender)) {
            return response()->json(['accepted' => true, 'ignored' => true]);
        }

        $duplicate = $this->inbound->acceptDuplicate(new InboundEmail(
            providerMessageId: (string) $payload['MessageID'],
            sender: $sender,
            body: '',
        ));

        if ($duplicate) {
            return response()->json(['accepted' => true, 'duplicate' => true]);
        }

        $authentication = $this->verifySenderAuthentication((array) ($payload['Headers'] ?? []));
        $body = trim((string) ($payload['StrippedTextReply'] ?? ''));

        if ($body === '') {
            $body = trim((string) ($payload['TextBody'] ?? ''));
        }

        if ($body === '') {
            $body = trim(strip_tags((string) ($payload['HtmlBody'] ?? '')));
        }

        try {
            $attachments = array_map(
                fn (array $attachment): InboundAttachment => InboundAttachment::fromPostmark($attachment),
                (array) ($payload['Attachments'] ?? []),
            );
        } catch (InvalidArgumentException $exception) {
            abort(403, $exception->getMessage());
        }

        $result = $this->inbound->accept(new InboundEmail(
            providerMessageId: (string) $payload['MessageID'],
            sender: $sender,
            body: $body,
            subject: $payload['Subject'] ?? null,
            senderAuthenticated: $authentication['authenticated'],
            spamScore: $authentication['spam_score'],
            rfcMessageId: $this->rfcMessageId((array) ($payload['Headers'] ?? [])),
            threadToken: $payload['MailboxHash'] ?? null,
            attachments: $attachments,
        ));

        return response()->json(['accepted' => true, ...($result['duplicate'] ? ['duplicate' => true] : [])]);
    }

    private function verifyBasicAuthentication(Request $request): void
    {
        $username = $this->email->webhookUsername();
        $password = $this->email->webhookPassword();
        abort_if($username === '' || $password === '', 503, 'Postmark webhook credentials are not configured.');

        if (! hash_equals($username, (string) $request->getUser()) || ! hash_equals($password, (string) $request->getPassword())) {
            abort(401, 'Invalid webhook credentials', ['WWW-Authenticate' => 'Basic realm="Secretary"']);
        }
    }

    /**
     * @param  array<int, array{Name?: string, Value?: string|null}>  $headers
     * @return array{authenticated: bool, spam_score: float|null}
     */
    private function verifySenderAuthentication(array $headers): array
    {
        $headers = collect($headers)->groupBy(fn (array $header): string => mb_strtolower((string) ($header['Name'] ?? '')));

        if ($headers->get('x-spam-tests', collect())->count() > 1 || $headers->get('x-spam-score', collect())->count() > 1) {
            abort(403, 'The inbound email contained ambiguous authentication headers.');
        }

        $headerValue = fn (string $name): string => (string) data_get($headers->get($name)?->first(), 'Value', '');
        $score = $headerValue('x-spam-score');
        $score = is_numeric($score) ? (float) $score : null;

        if ($score !== null && $score > (float) config('secretary.email.max_spam_score', 5.0)) {
            abort(403, 'The inbound email exceeded Secretary\'s spam threshold.');
        }

        $tests = collect(preg_split('/\s*,\s*/', mb_strtoupper($headerValue('x-spam-tests')), -1, PREG_SPLIT_NO_EMPTY));
        $authenticated = $tests->contains('DKIM_VALID_AU');

        if (config('secretary.email.require_sender_authentication', true) && ! $authenticated) {
            abort(403, 'The inbound sender did not pass author-domain DKIM authentication.');
        }

        return ['authenticated' => $authenticated, 'spam_score' => $score];
    }

    /** @param  array<int, array{Name?: string, Value?: string|null}>  $headers */
    private function rfcMessageId(array $headers): ?string
    {
        $values = collect($headers)
            ->filter(fn (array $header): bool => mb_strtolower((string) ($header['Name'] ?? '')) === 'message-id')
            ->pluck('Value');

        if ($values->count() !== 1) {
            return null;
        }

        $value = trim((string) $values->first());

        return preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/D', $value) === 1 ? $value : null;
    }
}
