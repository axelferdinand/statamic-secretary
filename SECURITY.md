# Security policy

Please report suspected vulnerabilities privately to `kontakt@prototypen.no`. Do not include production API keys, customer content, or other secrets in an issue or proof of concept.

The supported line is the latest tagged release. Security fixes may require upgrading Statamic, Laravel, PHP, or the addon. Public disclosure should wait until a fix is available and affected users have had a reasonable opportunity to update.

Secretary intentionally has no shell, generic filesystem, generic HTTP, delete, asset replacement, template, blueprint, or configuration tool. Its narrow asset access is container-allowlisted, native-permission-filtered, supported-image-only, size-bounded, and append-only. Reports that demonstrate a boundary escape, authorization bypass, sender spoofing, unsafe attachment acceptance, unintended publication, secret disclosure, or prompt-injection path that reaches an unauthorized tool are especially important.
