<?php

/** @var string $locale */
$isNorwegian = $locale === 'nb';
$homeUrl = $isNorwegian ? '/no' : '/';
$copy = $isNorwegian ? [
    'html_lang' => 'nb',
    'title' => 'Siden finnes ikke – Statamic Secretary',
    'description' => 'Siden du leter etter finnes ikke.',
    'skip' => 'Hopp til innholdet',
    'kicker' => '404 · Lite innholdsavvik',
    'heading' => 'Denne siden finnes ikke.',
    'body' => 'Secretary fant ingen side på denne adressen. Forsiden er fortsatt på plass.',
    'cta' => 'Til forsiden',
    'language' => 'English',
] : [
    'html_lang' => 'en',
    'title' => 'Page not found – Statamic Secretary',
    'description' => 'The page you are looking for does not exist.',
    'skip' => 'Skip to content',
    'kicker' => '404 · Tiny content mismatch',
    'heading' => 'Page not found.',
    'body' => 'Secretary could not find a page at this address. The homepage is still exactly where it should be.',
    'cta' => 'Back to the homepage',
    'language' => 'Norsk',
];
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $e($copy['html_lang']) ?>" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($copy['title']) ?></title>
    <meta name="description" content="<?= $e($copy['description']) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#082f3d">
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/secretary-icon.png">
    <link rel="stylesheet" href="/assets/secretary.css">
    <script src="/assets/secretary.js" defer></script>
</head>
<body class="not-found-body">
    <a class="skip-link" href="#main"><?= $e($copy['skip']) ?></a>

    <header class="site-header is-scrolled" data-header>
        <div class="shell nav">
            <a class="brand" href="<?= $e($homeUrl) ?>" aria-label="Statamic Secretary">
                <img src="/assets/secretary-icon.png" alt="" width="52" height="52">
                <span>Statamic <strong>Secretary</strong></span>
            </a>
            <a class="language-link" href="<?= $isNorwegian ? '/' : '/no' ?>" lang="<?= $isNorwegian ? 'en' : 'nb' ?>">
                <?= $e($copy['language']) ?>
            </a>
        </div>
    </header>

    <main id="main" class="not-found-page">
        <div class="not-found-orbit" aria-hidden="true"></div>
        <section class="shell not-found-card">
            <div>
                <p class="eyebrow"><span></span><?= $e($copy['kicker']) ?></p>
                <h1><?= $e($copy['heading']) ?></h1>
                <p><?= $e($copy['body']) ?></p>
                <a class="button button-coral" href="<?= $e($homeUrl) ?>">
                    <?= $e($copy['cta']) ?><span aria-hidden="true">→</span>
                </a>
            </div>
            <p class="not-found-code" aria-hidden="true">404</p>
        </section>
    </main>
</body>
</html>
