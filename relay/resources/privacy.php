<?php

/** @var string $locale */
/** @var string|null $analyticsMeasurementId */
$isNorwegian = $locale === 'nb';
$siteUrl = $isNorwegian
    ? 'https://secretary.statamic.no/no/personvern'
    : 'https://secretary.statamic.no/privacy';
$homeUrl = $isNorwegian ? '/no' : '/';
$ogImageUrl = 'https://secretary.statamic.no/assets/statamic-secretary-og.png';
$copy = $isNorwegian ? [
    'html_lang' => 'nb',
    'title' => 'Personvern – Secretary for Statamic',
    'description' => 'Slik bruker landingssiden til Secretary for Statamic valgfri Google Analytics, samtykke og nødvendige serverlogger.',
    'skip' => 'Hopp til innholdet',
    'language' => 'English',
    'breadcrumb_home' => 'Forside',
    'breadcrumb_current' => 'Personvern',
    'eyebrow' => 'Personvern',
    'heading' => 'Personvern, uten tåkeprat.',
    'intro' => 'Kortversjonen: Landingssiden bruker ingen analysecookies før du uttrykkelig sier ja. Du kan avslå eller trekke tilbake samtykket når som helst.',
    'updated' => 'Sist oppdatert 28. juli 2026',
    'site_title' => 'Når du besøker landingssiden',
    'site_body' => 'Webserveren og hosting-leverandøren kan føre kortvarige tekniske logger med IP-adresse, tidspunkt, forespurt URL, statuskode og nettleserens user agent. Dette brukes til sikkerhet, feilsøking og stabil drift – ikke til annonsering.',
    'analytics_title' => 'Valgfri besøksanalyse',
    'analytics_enabled' => 'Google Analytics 4 er konfigurert, men Google-taggen lastes først etter at du velger «Godta analyse». Før samtykke sendes det ingen analyseforespørsler til Google.',
    'analytics_disabled' => 'Landingssiden er klargjort for Google Analytics 4, men analyse er ikke aktiv fordi det ikke er konfigurert noen gyldig målings-ID.',
    'analytics_details' => 'Ved samtykke kan Google Analytics sette informasjonskapslene _ga og _ga_<id> for å skille besøk og økter. Annonse­lagring, annonsepersonalisering, Google Signals og deling av brukerdata til annonsering er avslått. Google kan behandle analysedata utenfor Norge og EØS i tråd med sine avtaler og overføringsgrunnlag.',
    'choice_title' => 'Ditt valg',
    'choice_body' => 'Samtykkevalget lagres lokalt i nettleseren under nøkkelen statamic-secretary-analytics-consent-v1. Velger du «Bare nødvendige», lastes ikke Google-taggen. Åpne «Personvernvalg» i footeren for å endre valget. Når et samtykke trekkes tilbake, stoppes videre måling og kjente _ga-cookies slettes.',
    'service_title' => 'Secretary-tjenesten',
    'service_body' => 'Denne erklæringen gjelder landingssiden. Selve addonen er selvhostet, og hver nettstedseier styrer sin Statamic-installasjon, OpenAI-konto, e-posttransport og lagring. Den valgfrie driftede e-posttjenesten behandler data som trengs for sikker ruting og svar. Ved abonnement behandler Stripe kontakt-, betalings-, faktura- og abonnementsdata som betalingsleverandør. Secretary lagrer bare Stripe-identifikatorer og abonnementsstatus som trengs for å åpne eller sperre relay.',
    'service_link' => 'Les den tekniske personvernerklæringen på GitHub',
    'contact_title' => 'Kontakt',
    'contact_body' => 'Spørsmål om personvern, innsyn eller sletting kan sendes til',
    'consent_open' => 'Personvernvalg',
    'consent_title' => 'Kan vi telle besøket ditt?',
    'consent_body' => 'Vi bruker Google Analytics for å forstå hvilke deler av siden som er nyttige. Google-taggen lastes først hvis du sier ja.',
    'consent_accept' => 'Godta analyse',
    'consent_decline' => 'Bare nødvendige',
    'footer_home' => 'Forside',
    'footer_github' => 'GitHub',
    'footer_privacy' => 'Personvern',
] : [
    'html_lang' => 'en',
    'title' => 'Privacy – Secretary for Statamic',
    'description' => 'How the Secretary for Statamic landing site uses optional Google Analytics, consent, and necessary server logs.',
    'skip' => 'Skip to content',
    'language' => 'Norsk',
    'breadcrumb_home' => 'Home',
    'breadcrumb_current' => 'Privacy',
    'eyebrow' => 'Privacy',
    'heading' => 'Privacy, without the fog.',
    'intro' => 'The short version: this landing site uses no analytics cookies until you actively say yes. You can decline or withdraw consent at any time.',
    'updated' => 'Last updated July 28, 2026',
    'site_title' => 'When you visit the landing site',
    'site_body' => 'The web server and hosting provider may keep short-lived technical logs containing an IP address, timestamp, requested URL, status code, and browser user agent. These are used for security, troubleshooting, and reliable operation—not advertising.',
    'analytics_title' => 'Optional visitor analytics',
    'analytics_enabled' => 'Google Analytics 4 is configured, but the Google tag loads only after you choose “Accept analytics.” Before consent, no analytics request is sent to Google.',
    'analytics_disabled' => 'The landing site is prepared for Google Analytics 4, but analytics is not active because no valid measurement ID is configured.',
    'analytics_details' => 'After consent, Google Analytics may set the _ga and _ga_<id> cookies to distinguish visits and sessions. Ad storage, ad personalization, Google Signals, and sharing user data for advertising remain disabled. Google may process analytics data outside Norway and the EEA under its contractual transfer mechanisms.',
    'choice_title' => 'Your choice',
    'choice_body' => 'Your choice is stored locally in the browser under statamic-secretary-analytics-consent-v1. If you choose “Necessary only,” the Google tag does not load. Open “Privacy choices” in the footer to change your choice. Withdrawing consent stops further measurement and removes known _ga cookies.',
    'service_title' => 'The Secretary service',
    'service_body' => 'This notice covers the landing site. The addon itself is self-hosted, and each site owner controls their Statamic installation, OpenAI account, mail transport, and retention. The optional hosted email service processes the data needed for secure routing and replies. For subscriptions, Stripe processes contact, payment, invoice, and subscription data as the payment provider. Secretary stores only the Stripe identifiers and subscription status needed to allow or block relay access.',
    'service_link' => 'Read the technical privacy notice on GitHub',
    'contact_title' => 'Contact',
    'contact_body' => 'Questions about privacy, access, or deletion can be sent to',
    'consent_open' => 'Privacy choices',
    'consent_title' => 'May we count your visit?',
    'consent_body' => 'We use Google Analytics to understand which parts of this site are useful. The Google tag loads only after you say yes.',
    'consent_accept' => 'Accept analytics',
    'consent_decline' => 'Necessary only',
    'footer_home' => 'Home',
    'footer_github' => 'GitHub',
    'footer_privacy' => 'Privacy',
];

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => $copy['breadcrumb_home'],
            'item' => $isNorwegian
                ? 'https://secretary.statamic.no/no'
                : 'https://secretary.statamic.no/',
        ],
        [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $copy['breadcrumb_current'],
            'item' => $siteUrl,
        ],
    ],
];
$jsonFlags = JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="<?= $e($copy['html_lang']) ?>" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($copy['title']) ?></title>
    <meta name="description" content="<?= $e($copy['description']) ?>">
    <meta name="theme-color" content="#082f3d">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= $e($siteUrl) ?>">
    <link rel="alternate" hreflang="en" href="https://secretary.statamic.no/privacy">
    <link rel="alternate" hreflang="nb" href="https://secretary.statamic.no/no/personvern">
    <link rel="alternate" hreflang="x-default" href="https://secretary.statamic.no/privacy">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $e($copy['title']) ?>">
    <meta property="og:description" content="<?= $e($copy['description']) ?>">
    <meta property="og:url" content="<?= $e($siteUrl) ?>">
    <meta property="og:image" content="<?= $e($ogImageUrl) ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= $e($copy['title']) ?>">
    <meta property="og:site_name" content="Secretary for Statamic">
    <meta property="og:locale" content="<?= $isNorwegian ? 'nb_NO' : 'en_US' ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $e($copy['title']) ?>">
    <meta name="twitter:description" content="<?= $e($copy['description']) ?>">
    <meta name="twitter:image" content="<?= $e($ogImageUrl) ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/secretary-icon.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/assets/secretary-icon.png">
    <?php if ($analyticsMeasurementId !== null) { ?>
        <meta name="google-analytics-id" content="<?= $e($analyticsMeasurementId) ?>">
    <?php } ?>
    <link rel="stylesheet" href="/assets/secretary.css">
    <script src="/assets/secretary.js" defer></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbSchema, $jsonFlags) ?></script>
</head>
<body>
    <a class="skip-link" href="#main"><?= $e($copy['skip']) ?></a>

    <header class="site-header is-scrolled" data-header>
        <div class="shell nav">
            <a class="brand" href="<?= $e($homeUrl) ?>" aria-label="Secretary for Statamic">
                <img src="/assets/secretary-icon.png" alt="" width="52" height="52">
                <span>Statamic <strong>Secretary</strong></span>
            </a>
            <div class="nav-actions">
                <a class="language-link" href="<?= $isNorwegian ? '/privacy' : '/no/personvern' ?>" lang="<?= $isNorwegian ? 'en' : 'nb' ?>">
                    <?= $e($copy['language']) ?>
                </a>
                <a class="button button-small button-mint" href="<?= $e($homeUrl) ?>">
                    <?= $e($copy['breadcrumb_home']) ?><span aria-hidden="true">↗</span>
                </a>
            </div>
        </div>
    </header>

    <main id="main" class="legal-page">
        <section class="legal-hero">
            <div class="shell legal-shell">
                <nav class="breadcrumbs" aria-label="<?= $e($copy['breadcrumb_current']) ?>">
                    <a href="<?= $e($homeUrl) ?>"><?= $e($copy['breadcrumb_home']) ?></a>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page"><?= $e($copy['breadcrumb_current']) ?></span>
                </nav>
                <p class="eyebrow"><span></span><?= $e($copy['eyebrow']) ?></p>
                <h1><?= $e($copy['heading']) ?></h1>
                <p class="legal-intro"><?= $e($copy['intro']) ?></p>
                <p class="legal-updated"><?= $e($copy['updated']) ?></p>
            </div>
        </section>

        <section class="section legal-content">
            <div class="shell legal-shell">
                <article class="legal-card">
                    <section>
                        <span class="legal-number" aria-hidden="true">01</span>
                        <div>
                            <h2><?= $e($copy['site_title']) ?></h2>
                            <p><?= $e($copy['site_body']) ?></p>
                        </div>
                    </section>
                    <section>
                        <span class="legal-number" aria-hidden="true">02</span>
                        <div>
                            <h2><?= $e($copy['analytics_title']) ?></h2>
                            <p><?= $e($analyticsMeasurementId !== null ? $copy['analytics_enabled'] : $copy['analytics_disabled']) ?></p>
                            <?php if ($analyticsMeasurementId !== null) { ?>
                                <p><?= $e($copy['analytics_details']) ?></p>
                            <?php } ?>
                        </div>
                    </section>
                    <section>
                        <span class="legal-number" aria-hidden="true">03</span>
                        <div>
                            <h2><?= $e($copy['choice_title']) ?></h2>
                            <p><?= $e($copy['choice_body']) ?></p>
                            <?php if ($analyticsMeasurementId !== null) { ?>
                                <button class="button button-dark legal-consent-button" type="button" data-consent-open>
                                    <?= $e($copy['consent_open']) ?>
                                </button>
                            <?php } ?>
                        </div>
                    </section>
                    <section>
                        <span class="legal-number" aria-hidden="true">04</span>
                        <div>
                            <h2><?= $e($copy['service_title']) ?></h2>
                            <p><?= $e($copy['service_body']) ?></p>
                            <p><a href="https://github.com/axelferdinand/statamic-secretary/blob/main/PRIVACY.md" rel="noopener"><?= $e($copy['service_link']) ?> ↗</a></p>
                        </div>
                    </section>
                    <section>
                        <span class="legal-number" aria-hidden="true">05</span>
                        <div>
                            <h2><?= $e($copy['contact_title']) ?></h2>
                            <p><?= $e($copy['contact_body']) ?> <a href="mailto:kontakt@prototypen.no">kontakt@prototypen.no</a>.</p>
                        </div>
                    </section>
                </article>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="shell footer-grid">
            <a class="brand footer-brand" href="<?= $e($homeUrl) ?>">
                <img src="/assets/secretary-icon.png" alt="" width="46" height="46">
                <span>Statamic <strong>Secretary</strong></span>
            </a>
            <p><?= $e($copy['description']) ?></p>
            <nav aria-label="Footer">
                <a href="<?= $e($homeUrl) ?>"><?= $e($copy['footer_home']) ?></a>
                <a href="https://github.com/axelferdinand/statamic-secretary" rel="noopener"><?= $e($copy['footer_github']) ?></a>
                <a href="<?= $e($siteUrl) ?>" aria-current="page"><?= $e($copy['footer_privacy']) ?></a>
                <?php if ($analyticsMeasurementId !== null) { ?>
                    <button class="footer-link-button" type="button" data-consent-open><?= $e($copy['consent_open']) ?></button>
                <?php } ?>
            </nav>
        </div>
    </footer>

    <?php if ($analyticsMeasurementId !== null) { ?>
        <section class="consent-manager" role="region" aria-labelledby="consent-title" data-consent-manager hidden>
            <div class="shell consent-inner">
                <div>
                    <p class="consent-kicker"><?= $e($copy['eyebrow']) ?></p>
                    <h2 id="consent-title"><?= $e($copy['consent_title']) ?></h2>
                    <p><?= $e($copy['consent_body']) ?></p>
                </div>
                <div class="consent-actions">
                    <button class="button button-mint" type="button" data-consent-accept><?= $e($copy['consent_accept']) ?></button>
                    <button class="button consent-decline" type="button" data-consent-decline><?= $e($copy['consent_decline']) ?></button>
                </div>
            </div>
        </section>
    <?php } ?>
</body>
</html>
