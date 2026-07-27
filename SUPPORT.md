# Support

For reproducible addon defects, open an issue in the package repository once it is public. Include the Secretary version, Statamic/Laravel/PHP versions, database driver, relevant sanitized logs, and whether the request came from the Control Panel or email.

Never include OpenAI keys, Postmark tokens/passwords, raw customer content, or inbound email payloads containing personal data. Security issues should follow [SECURITY.md](SECURITY.md) instead of a public issue.

The initial compatibility target is Statamic 6 on PHP 8.3 or newer. Production OpenAI, Postmark, hosting, mail-delivery, and queue configuration remain the site operator's responsibility.
