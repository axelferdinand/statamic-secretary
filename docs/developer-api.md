# Secretary developer API

## Config as code

Publish the addon config and keep shared editorial rules in source control:

```shell
php artisan vendor:publish --tag=statamic-secretary-config
```

```php
'editorial' => [
    'defaults' => [
        'voice' => 'Clear, warm and direct.',
        'avoid' => 'Unsupported claims and AI clichés.',
    ],
    'sites' => [
        'default' => [
            'audience' => 'Editors evaluating Statamic add-ons.',
            'terminology' => 'Always write Statamic Secretary in full.',
        ],
    ],
],
```

Control Panel values are stored per site and override these defaults. The model receives the effective guide as trusted application configuration, not as content returned by a tool.

## Read-only tools

Custom tools provide application context without widening Secretary's content mutation surface. Implement `AxelFerdinand\StatamicSecretary\Contracts\SecretaryTool`:

```php
final class ReadCampaignContext implements SecretaryTool
{
    public function name(): string
    {
        return 'read_campaign_context';
    }

    public function description(): string
    {
        return 'Read an approved campaign brief by handle.';
    }

    public function parameters(): array
    {
        return ['campaign' => ['type' => 'string']];
    }

    public function required(): array
    {
        return ['campaign'];
    }

    public function execute(SecretaryToolContext $context): array
    {
        return [
            'ok' => true,
            'brief' => Campaign::findByHandle($context->arguments['campaign'])?->publicBrief(),
        ];
    }
}
```

Register the class in `secretary.developer.tools`, or call `app(ToolRegistry::class)->register($tool)` from an application service provider.

Tool names must be unique snake_case identifiers. Parameters become a strict JSON schema. Results must be JSON serializable and are treated as untrusted data. Custom tools must not write content, configuration, files, or external state. Secretary does not expose a mutation extension hook; writes remain blueprint-validated, content-bounded change sets.

## Developer mode

```dotenv
SECRETARY_DEVELOPER_MODE=true
SECRETARY_OPENAI_INPUT_COST_PER_MILLION=0
SECRETARY_OPENAI_OUTPUT_COST_PER_MILLION=0
```

Users with `configure secretary` then see:

- model, total duration and tool rounds;
- aggregated input/output tokens;
- optional estimated cost using the configured rates;
- tool name, sanitized arguments, result status and duration.

Developer mode never exposes hidden reasoning, full tool results, API keys, webhook secrets, content fingerprints, or complete generated patch bodies.

## CI and dry-run

```shell
php please secretary:doctor --json
php please secretary:dry-run \
  "Check the home page against the editorial guide." \
  --user=editor@example.com \
  --entry=home \
  --json
```

Dry-run uses the real Statamic user policy, OpenAI model, schemas, read tools, validation and audit database. Entry mutation calls stop at a `proposed` change set and never create or update content. Other Statamic resources already use database staging; the dry-run conversation is therefore permanently blocked in `ChangeSetPublisher` and can never be published.

The command exits non-zero for missing configuration, an invalid user/entry, model/tool failures, or blocking diagnostics. Use a dedicated least-privileged Statamic user in CI.

## Laravel events

The addon dispatches application events with secret-free payload methods:

- `MessageReceived` → `message.received`
- `AgentCompleted` → `agent.completed`
- `ChangeSetPrepared` → `change.prepared`
- `ChangeSetPublished` → `change.published`

Each event exposes its Eloquent model for in-process listeners and a bounded `payload()` for transport. Publication remains explicit; listening to an event does not grant Secretary permissions.

## Signed webhooks

```dotenv
SECRETARY_WEBHOOKS_ENABLED=true
SECRETARY_WEBHOOK_URL=https://example.com/webhooks/secretary
SECRETARY_WEBHOOK_SECRET=use-at-least-32-random-characters
SECRETARY_WEBHOOK_EVENTS=message.received,agent.completed,change.prepared,change.published
SECRETARY_WEBHOOK_TIMEOUT=10
```

Webhook delivery runs on the `secretary` queue with bounded retries. Requests contain:

```text
X-Secretary-Event: change.published
X-Secretary-Signature: sha256=<hex HMAC>
Content-Type: application/json
```

Verify `hash_hmac('sha256', $exactRawBody, $secret)` using a constant-time comparison before decoding or acting on the payload. The webhook URL must be HTTPS and the secret must contain at least 32 characters; both checks appear in CLI and Control Panel diagnostics.

## Operational diagnostics

`secretary:doctor` and the Control Panel system-status card are backed by the same report service. They cover OpenAI, content root, migrations, revisions, queue/retry window, relay, email transport, configured developer tools, and webhook signing configuration without returning credential values.
