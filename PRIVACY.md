# Privacy

Statamic Secretary is self-hosted. The site operator controls the Statamic installation, database, mail transport, OpenAI project, retention policy, and who may use the addon.

## Data processed

Secretary stores conversation messages, provider message IDs, authenticated Statamic user IDs/email addresses, OpenAI response IDs, model usage metadata, and audited content change sets in the site's database. It sends the user's request, relevant conversation context, and content returned by the allowlisted read tools to the configured OpenAI Responses API project.

When email is enabled, Postmark parses inbound mail and POSTs the message, sender, authentication/spam headers, and thread hash to the site. Outbound replies use Secretary's isolated Postmark transport and do not replace the site's default Laravel mailer.

API keys remain in server environment configuration. They are not stored in content, conversation records, settings records, or browser properties. Secretary stores the chosen public sender address, Postmark inbound address, server identifier/name, delivery type, webhook endpoint, and a non-secret credentials fingerprint as encrypted database settings. The webhook password is derived from the site's `APP_KEY` and is never returned to the browser.

## Retention and deletion

Records remain in the site database until the operator removes them. `php please secretary:prune --days=90` interactively removes conversations older than the specified window together with their messages and change sets. Use `--force` only in a deliberate scheduled task. `SECRETARY_RETENTION_DAYS` sets the command's default window.

OpenAI response storage is controlled with `SECRETARY_OPENAI_STORE`. Set it to `false` to avoid stored Responses API continuation and use locally stored message history instead. The site operator remains responsible for configuring appropriate retention with OpenAI, Postmark, database backups, and mail logs.

## Operator responsibilities

Before production use, the site operator should document this processing in its own privacy notice, select lawful retention periods, restrict Secretary permissions and any optional sender allowlist, and review the applicable OpenAI and Postmark data-processing terms.
