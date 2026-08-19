# Shared `secretary@statamic.no` relay

The Composer addon is deliberately site-local: every installation owns its OpenAI key, content boundary, users, database, Postmark server, and webhook. Reusing one public mailbox across unrelated sites requires a separate hosted relay. Pointing one Postmark inbound server at several addon installations is not possible and must never be approximated by broadcasting an email to candidate sites.

This document is the security and routing contract for the optional hosted relay. The addon contains the disabled-by-default signed inbound endpoint, signed reply client, and control-panel pairing client described below. A framework-independent central routing core lives in `relay/` and is excluded from the Composer addon archive. It implements deterministic routing, ambiguity rejection, an idempotent no-forward selection notice, Postmark inbound normalization, signed site delivery, reply verification, idempotent outbound binding, an encrypted durable SQLite store, crash-safe atomic claim leases, a DNS-pinned public-HTTPS transport, retry-safe email-verified pairing, redacted operator controls, two-phase signing-secret and route rotation, durable endpoint rate limits, redacted security events, and a standalone HTTP front controller with migration, Postmark configuration, provisioning, pairing, and retention commands. The relay is deployed at `https://secretary.statamic.no`; the remaining production gate is the two-installation live isolation proof.

## Addon-side implementation

When pairing is offered, the installation enables the control-panel setup:

```dotenv
SECRETARY_RELAY_PAIRING_ENABLED=true
SECRETARY_RELAY_BASE_URL=https://secretary.statamic.no
```

An authorized administrator first confirms the site's public HTTPS URL. The addon prefers the selected Statamic site's URL, falls back to a public production `APP_URL`, and uses the hostname to preview the actual `@statamic.no` alias; it never derives the site from the administrator's email address. The administrator then confirms an existing Statamic user with `use secretary`. The addon asks `POST /v1/pairings/request` to send a 15-minute code to that address; the HTTP response is generic and never contains the code. The public URL is retained with the pending request so the administrator only needs to paste the code on the next step. The addon creates a stable retry ID and posts to `/v1/pairings/claim`.

When Stripe billing is configured, a new live installation receives only an installation ID, fixed annual price, and Stripe Checkout URL at this stage. Route credentials, signing secret, readable alias, inbound delivery, replies, and selection notices remain unavailable. A signed Stripe webhook changes the installation to an entitled state. Repeating the exact pairing claim after payment then returns and stores the route token, signing secret, address, sender, and relay URL in the encrypted Secretary settings table. The code itself is never stored by the addon. The relay stores only its digest and atomically binds the first claim; an exact retry returns the same isolated installation even after the initial code lifetime, while another claimant cannot reuse it. The public demo installation is explicitly marked `complimentary`; live installations are never inferred to be demos from request text or sender identity.

See [`relay-billing.md`](relay-billing.md) for the billing state machine and production activation order.

Manual provisioning remains available through environment values:

```dotenv
SECRETARY_RELAY_ENABLED=true
SECRETARY_RELAY_INSTALLATION_ID=
SECRETARY_RELAY_ROUTE_TOKEN=
SECRETARY_RELAY_SIGNING_SECRET=
SECRETARY_RELAY_BASE_URL=https://secretary.statamic.no
SECRETARY_RELAY_CACHE_STORE=redis
```

The signing secret must decode to at least 256 bits. `SECRETARY_RELAY_CACHE_STORE` must be a persistent shared Laravel cache; the array store is refused by `secretary:doctor` because it cannot prevent a nonce replay in a later request. These values are intentionally separate from the site's OpenAI and Postmark credentials.

The relay posts to `POST /_secretary/webhooks/relay/inbound`. The route is disabled until the installation is fully configured. It accepts normalized version 1 for ordinary messages, version 2 when validated image attachments are present, and version 3 when the relay edge has already sent the receipt acknowledgement. It verifies the signature before parsing, then reuses the same sender allowlist, native Statamic user/permission check, input/attachment limits, queue, idempotency, publication gate, and audit path as direct Postmark delivery.

## Non-negotiable isolation rules

- Every installation receives an opaque installation ID and an independent 256-bit signing secret. The relay never shares one site's secret, OpenAI key, Postmark token, content, user list, or webhook URL with another site.
- A message is delivered to exactly one installation or none. Ambiguous routing is rejected before message content is forwarded.
- The relay never derives a site from a display name, email subject, prompt text, model output, or a fuzzy domain match.
- A sender must be registered for the resolved installation and must still map to an authorized Statamic user when the signed request reaches the addon.
- The addon verifies installation ID, timestamp, nonce, body digest, and HMAC signature before parsing the normalized email payload. Nonces are replay-protected in a shared cache.
- Reply and publication commands remain bound to the installation and conversation selected by the original authenticated inbound message.
- No model call is allowed in the relay. Site selection is deterministic application logic.

## Routing

The safest address is an opaque per-installation alias such as:

```text
secretary+r<25-lowercase-random-characters>@statamic.no
```

The route token is public but unguessable and maps to one active installation. It is not a database ID and can be rotated without changing the installation secret.

In direct inbound-domain mode, Postmark preserves an exact friendly recipient such as `customer.example@statamic.no` in `ToFull`. The adapter accepts exactly one valid shared-domain public alias and the router resolves it by exact unique `public_alias`; an unknown alias never falls back to sender-only routing. The registered sender, active installation, and subscription are then checked before message content is forwarded. Replies continue through the route-and-conversation `secretary+…` address and its validated `MailboxHash`.

The legacy cPanel-forwarder mode remains backward compatible. It appends the installation route as a plus tag; Postmark normally exposes that value as the top-level `MailboxHash`. Some forwarding servers strip the envelope plus tag on replies while Postmark still retains the original tagged address and its per-recipient `MailboxHash` in `ToFull`. When the top-level value is empty, the relay may use exactly one `ToFull` fallback only when the shared-domain address, its plus tag, and its per-recipient hash agree exactly. The core never derives routing from the legacy `To` string, `OriginalRecipient`, a subject, or message content. [Postmark documents inbound-domain forwarding and `MailboxHash` behavior.](https://postmarkapp.com/developer/user-guide/inbound/inbound-domain-forwarding)

Plain mail to `secretary@statamic.no` is supported only when the normalized sender address maps to exactly one active installation. If the sender belongs to zero installations, the relay rejects the message. If it belongs to more than one, the relay atomically sends one site-selection response and forwards no instruction or attachment. The response contains only labels and exact site-bound aliases, and tells the sender to resend the original request to one alias. The relay does not store or echo the original instruction in the notice and does not remember a global default that could silently route a future message to the wrong customer.

Replies use a route- and conversation-bound address:

```text
secretary+r<25-character-route>.c<25-character-conversation>@statamic.no
```

Both tokens are validated by the relay. Their compact lowercase form keeps the full reply address within the RFC 5321 64-octet local-part limit while retaining roughly 129 bits of entropy per token. The conversation token is opaque and must resolve inside the same installation as the route token.

## Signed delivery to an installation

The relay posts a normalized JSON payload to a dedicated addon endpoint over HTTPS and includes:

```text
Secretary-Installation: <opaque installation id>
Secretary-Timestamp: <unix seconds>
Secretary-Nonce: <random single-use value>
Secretary-Content-SHA256: <lowercase hex digest of the exact body>
Secretary-Signature: v1=<hex HMAC-SHA256>
```

The canonical signature input is the HTTP method, request path, installation ID, timestamp, nonce, and content digest separated by newlines. The addon accepts a narrow clock skew, compares signatures in constant time, consumes the nonce atomically, and then applies its existing payload-size, sender, native-user, DKIM, spam, threading, queue, and publication checks.

The normalized payload retains the provider message ID, plain-text body, subject, authenticated sender, author-domain authentication result, spam score, route token, and conversation token. HTML is excluded. Ordinary messages remain version 1 for backward compatibility. Version 2 adds an `attachments` array containing only validated JPEG, PNG, or WebP images with filename, MIME type, base64 bytes, exact byte length, and SHA-256 checksum. Version 3 adds `acknowledgement_sent: true`, preventing the site from sending a duplicate acknowledgement after the relay edge has already replied. The exact signed body covers every field and attachment byte.

The implemented version-1 field names are `provider_message_id`, `sender`, `subject`, `body`, `sender_authenticated`, `spam_score`, `route_token`, `conversation_token`, and `rfc_message_id`. Unknown fields are rejected. A new conversation receives a compact random token stored only in that site's database. Follow-ups must supply both that token and the same route token.

The relay validates count, per-image bytes, total bytes, extension/MIME agreement, and the actual decodable image before routing. It does not persist attachment content in SQLite. The paired addon validates the image and checksum again, applies the authorized Statamic user's native upload policy, and imports append-only into its configured asset container. Invalid or unsupported attachments are permanently rejected rather than silently discarded.

## Replies

An installation sends a signed reply request to the relay rather than receiving a shared Postmark token. The relay verifies the same installation identity, confirms that the referenced inbound message and recipient belong to that installation, assigns a unique provider idempotency key, and sends through the shared Postmark server with the route-bound `Reply-To` address.

The addon-side client posts version-1 JSON to `POST <SECRETARY_RELAY_BASE_URL>/v1/replies`. The payload includes a reply-ID-derived idempotency key, original provider message ID, recipient taken from the site-bound conversation, subject, plain-text body, review URL, change-set summaries, route token, conversation token, and validated RFC reply ID. The client refuses cross-conversation reply objects and never receives the shared Postmark token. The hosted core's Postmark adapter sends a single plain-text message through the official `/email` endpoint with the route-bound `ReplyTo`, message stream, and validated threading headers. [These fields are supported by Postmark's Email API.](https://postmarkapp.com/developer/api/email-api)

The relay stores only the routing and delivery metadata required for isolation, idempotency, abuse response, and retention. Site content and OpenAI requests remain on the installation. Inbound provider IDs and outbound reply idempotency keys are atomically bound to SHA-256 fingerprints of their exact normalized requests; a repeated identity with different content is a conflict, not a duplicate. Retention and data-processing terms must be explicit before public use.

## Signing-secret rotation

The operator can rotate one installation's HMAC secret without disabling it:

1. `rotate-installation-secret.php --action=prepare` atomically creates one encrypted pending secret. An exact retry returns the same rotation ID and secret.
2. The site owner runs `php please secretary:relay-install-secret-rotation sr_...` and pastes the secret into a hidden prompt. The addon stores the new and previous secrets inside its encrypted setting, signs replies with the new secret, and accepts inbound requests signed with either secret for the selected 5–60 minute grace window.
3. The operator immediately runs `rotate-installation-secret.php --action=promote` with the same rotation ID. The relay begins signing inbound requests with the new secret and accepts old replies until its grace window expires.

During step 2, the relay still signs inbound delivery with the old secret while accepting old or pending signatures from the site. After step 3, both sides prefer the new secret while accepting in-flight old signatures. Verification compares every eligible secret before making a decision, then consumes the nonce only after a valid signature. Exact prepare, site-install, and promote retries are safe; a different or stacked rotation is rejected. The site command refuses database rotation when `SECRETARY_RELAY_SIGNING_SECRET` overrides the encrypted setting, and never accepts the new secret as a command-line argument.

The operator must promote before the site's grace window expires.

## Route rotation

Route rotation uses a separate `rr_…` identity:

1. The relay atomically prepares one new `r…` token and records it as pending. A pending route resolves to its installation for validation, but cannot start a conversation.
2. `php please secretary:relay-install-route-rotation rr_... r...` makes the site use the new route, retains the old route in the encrypted setting, and grants the old route a 5–60 minute new-thread transition while the relay is still on it.
3. The relay promotes the exact rotation. The new route becomes current and the old route becomes retired in one transaction.

After promotion, a retired route without a conversation token is rejected. A retired route with an exact stored conversation token remains bound to the same installation and sender, allowing real email threads to outlive an alias rotation. Replies for those threads keep the old route-and-conversation `Reply-To`; new threads and ambiguity notices use only the current route. The addon accepts a retired route only when the payload supplies a syntactically valid conversation token, then its normal conversation lookup enforces the exact route/sender binding. A route belonging to another installation never passes that lookup.

Prepare, site installation, and promotion are all exact-retry safe. A stacked rotation is rejected during the transition, an environment-backed `SECRETARY_RELAY_ROUTE_TOKEN` cannot be silently overridden, and neither the CP status nor shared pairing response exposes retired routes.

## Required proof before release

Automated addon and hosted-core tests already cover valid delivery, wrong installation/secret/route, route/conversation/recipient/inbound substitution, expired requests, body mismatch, nonce replay, provider and reply duplicates, exact field allowlisting, native user mapping, ambiguous plain-address routing to neither site, compact RFC-valid reply aliases, Postmark DKIM/spam/plain-text normalization, multi-turn conversation reuse, the cross-package HMAC contract, one idempotent threaded outbound reply, pairing-code delivery only to the requested address, generic code-request responses, pairing-code expiry, digest-only storage, first-claim binding, exact retry, credential encryption, addon-side secret non-disclosure, encrypted two-phase signing-secret rotation, transition-direction compatibility, exact rotation retry, grace expiry, stacked-rotation rejection, pending-route rejection, atomic route promotion, retired-route new-thread rejection, retired-thread continuation/reply binding, two-worker rate-limit atomicity, HMAC-pseudonymized identities, reset/pruning, safe `429` responses, and secret-free event records. The hosted system still requires all of the following end-to-end proof:

## Production work still required

- Customer-visible revoke and additional-sender management. Email-verified initial pairing and operator-only retry-safe enable/disable, sender controls, signing-secret rotation, and route rotation are implemented.
- Abuse response, customer-visible audit history, metrics, alerting, verified backups, and a privacy/DPA review. Durable application rate limits and redacted security events are implemented.
- Formal ownership, rotation, backup, and incident-response procedures for the deployed hosting, secrets, forwarding, and Postmark configuration.

- Two installations with different signing secrets and overlapping user email scenarios.
- Alias rotation and disabled-installation behavior.
- Ambiguous plain-address routing that forwards to neither site.
- Cross-installation route/conversation substitution attempts.
- Invalid, expired, replayed, and body-mismatched signatures.
- Duplicate Postmark deliveries and duplicate outbound requests.
- A full new thread, multi-turn reply, draft, and authenticated publication on site A with byte-for-byte verification that site B's content and database remain unchanged.
