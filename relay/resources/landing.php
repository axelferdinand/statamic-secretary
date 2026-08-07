<?php

/** @var string $locale */
/** @var string|null $analyticsMeasurementId */
$isNorwegian = $locale === 'nb';
$marketplaceUrl = 'https://statamic.com/marketplace';
$siteUrl = $isNorwegian ? 'https://secretary.statamic.no/no' : 'https://secretary.statamic.no/';
$privacyUrl = $isNorwegian ? '/no/personvern' : '/privacy';
$ogImageUrl = 'https://secretary.statamic.no/assets/statamic-secretary-og.png';

$copy = $isNorwegian ? [
    'html_lang' => 'nb',
    'title' => 'Secretary for Statamic – AI-assistent for Statamic-innhold',
    'description' => 'Be om Statamic-endringer på e-post eller i kontrollpanelet. Secretary følger blueprintene, lager trygge utkast og publiserer bare når du ber om det.',
    'skip' => 'Hopp til innholdet',
    'nav_demo' => 'Se demo',
    'nav_safety' => 'Slik jobber den',
    'nav_pricing' => 'Pris',
    'nav_cta' => 'Hent Secretary',
    'language' => 'English',
    'eyebrow' => 'En innholdsassistent for Statamic 6',
    'hero_title_1' => 'Statamic-siden din',
    'hero_title_2' => 'har fått sekretær.',
    'hero_lead' => 'Send en e-post. Spør i kontrollpanelet. Få et strukturert utkast som faktisk passer blueprints, innhold og publiseringsflyt.',
    'hero_cta' => 'Hent Secretary — $49',
    'hero_demo' => 'Se den jobbe',
    'hero_note' => 'Éngangskjøp · via Statamic Marketplace',
    'stamp_1' => 'Kun innhold',
    'stamp_2' => 'Utkast først',
    'stamp_3' => 'Mennesket bestemmer',
    'window_label' => 'Secretary · Kontrollpanel',
    'window_status' => 'klar',
    'chat_1' => 'Kan du oppdatere fredagstidene til kl. 16 og gjøre meldingen på forsiden litt varmere?',
    'chat_2' => 'Klart. Jeg fant åpningstidene og varselet i Home-blueprintet. Utkastet er klart til gjennomgang.',
    'change_label' => '2 endringer · utkast',
    'change_from' => 'Fre 09:00–18:00',
    'change_to' => 'Fre 09:00–16:00',
    'micro_1' => 'Mindre småplukk.',
    'micro_2' => 'Mer publisering.',
    'micro_3' => 'Færre “kan du bare …?”',
    'problem_kicker' => 'Den bitte lille endringen',
    'problem_title' => 'Ingen bitte liten utvikleroppgave.',
    'problem_body' => 'Åpningstiden, ingressen og den nye undersiden trenger ikke vente på neste sprint. Secretary gjør redaktørens instruksjon om til et trygt, synlig utkast—uten å rote rundt i templates, config eller kode.',
    'feature_1_title' => 'Snakk menneske. På ditt språk.',
    'feature_1_body' => 'Skriv det du ville skrevet til en kollega, på språket teamet ditt bruker. Secretary følger språket ditt, finner riktig innhold og stiller oppfølgingsspørsmål når noe mangler.',
    'feature_2_title' => 'Behold Statamic',
    'feature_2_body' => 'Felter valideres mot de faktiske blueprintene. Struktur, multisite, tillatelser og revisjoner er en del av jobben.',
    'feature_3_title' => 'Se før du sender',
    'feature_3_body' => 'Alle endringer blir utkast. Redaktøren kan finpusse i chatten og publiserer først når det sies uttrykkelig.',
    'demo_kicker' => 'Prøv den nå',
    'demo_title' => 'Én beskjed. Et ordentlig utkast.',
    'demo_body' => 'Dette er en liten simulering, men arbeidsflyten er den samme i Statamic: Secretary leser strukturen, foreslår endringen og lar deg bestemme.',
    'demo_email' => 'E-post',
    'demo_cp' => 'Kontrollpanel',
    'demo_to' => 'Til',
    'demo_subject' => 'Emne',
    'demo_subject_value' => 'En liten endring på forsiden',
    'demo_prompt_label' => 'Instruksjon',
    'demo_prompt_1' => 'Oppdater fredagstidene til kl. 16 og gjør meldingen på forsiden litt varmere.',
    'demo_prompt_2' => 'Lag en ny side for Bedriftsrådgivning under Tjenester. Hold den som utkast.',
    'demo_prompt_3' => 'Kort ned ingressen på Om oss. Behold tonen, men fjern gjentakelser.',
    'demo_chip_1' => 'Åpningstider',
    'demo_chip_2' => 'Ny side',
    'demo_chip_3' => 'Finpuss tekst',
    'demo_send' => 'Send til Secretary',
    'demo_running' => 'Secretary jobber …',
    'demo_reset' => 'Prøv igjen',
    'demo_step_1' => 'Instruksjonen er mottatt',
    'demo_step_2' => 'Leser blueprint og eksisterende innhold',
    'demo_step_3' => 'Validerer felter og tillatelser',
    'demo_step_4' => 'Utkastet er klart',
    'demo_result_title' => 'Klart til gjennomgang',
    'demo_result_body' => 'Secretary endret bare de to feltene du ba om. Ingenting er publisert.',
    'demo_before' => 'Før',
    'demo_after' => 'Utkast',
    'demo_old_notice' => 'Vi stenger tidlig på fredag.',
    'demo_new_notice' => 'Vi runder av litt tidligere denne fredagen. Vi ses før kl. 16!',
    'demo_result_note' => 'Gjennomgå. Finpuss. Publiser. Det er hele greia.',
    'how_kicker' => 'To innganger. Samme trygge jobb.',
    'how_title' => 'Jobb der du allerede jobber.',
    'email_title' => 'Send en e-post',
    'email_body' => 'Svar i samme tråd for å finpusse. Når du ber om publisering, sjekker Secretary rettigheter og at ingen har endret innholdet i mellomtiden.',
    'cp_title' => 'Åpne chatten i Statamic',
    'cp_body' => 'Assistenten er tilgjengelig fra hele kontrollpanelet. Utkast og status oppdateres uten full sidelasting.',
    'shared_title' => 'Vil du slippe Postmark-oppsettet?',
    'shared_body' => 'Koble nettstedet til den driftede innboksen og få en egen adresse som eksempel.no@statamic.no. Avsender og installasjon pares sikkert, slik at riktig beskjed alltid går til riktig nettsted.',
    'safety_kicker' => 'Snill med innholdet. Paranoid med grensene.',
    'safety_title' => 'Den kan mye. Ikke alt.',
    'safety_body' => 'Secretary får ikke shell, en generell filskriver eller frie hender i prosjektet. Den får smale Statamic-verktøy som respekterer innholdsroten, blueprintene og redaktørens egne rettigheter.',
    'guard_1' => 'Ingen kode, templates eller config',
    'guard_2' => 'Ingen sletting eller vilkårlig filtilgang',
    'guard_3' => 'Ingen publisering uten uttrykkelig beskjed',
    'guard_4' => 'Ingen overskriving av nyere menneskelige endringer',
    'guard_5' => 'Full samtale- og endringshistorikk',
    'quote' => 'Den “vibe-koder” seg ikke gjennom YAML-en din.',
    'pricing_kicker' => 'En liten pris for færre småting',
    'pricing_title' => 'Kjøp addonen. Velg innboks.',
    'addon_label' => 'Statamic-addon',
    'addon_price' => '$49',
    'addon_period' => 'én gang',
    'addon_body' => 'Alt du trenger for å jobbe med Secretary i kontrollpanelet og via din egen Postmark-server.',
    'addon_feature_1' => 'Global chat i kontrollpanelet',
    'addon_feature_2' => 'Strukturerte, gjennomgåbare utkast',
    'addon_feature_3' => 'Egen Postmark-server og adresse',
    'addon_feature_4' => 'Oppdateringer for kjøpt hovedversjon',
    'addon_cta' => 'Kjøp på Marketplace',
    'hosted_badge' => 'Enkleste oppsett',
    'hosted_label' => 'Driftet innboks',
    'hosted_price' => '+$49',
    'hosted_period' => 'per år',
    'hosted_body' => 'Få eksempel.no@statamic.no og hopp over egen Postmark-server, innkommende webhook og postkasse.',
    'hosted_feature_1' => 'Egen, driftet adresse for nettstedet',
    'hosted_feature_2' => 'Sikker paring av avsender og nettsted',
    'hosted_feature_3' => 'Drift, ruting og leveringsforsøk inkludert',
    'hosted_feature_4' => 'Gratis å prøve på demoen',
    'hosted_cta' => 'Hent addonen og aktiver',
    'pricing_note' => 'Relay aktiveres med sikker Stripe-betaling inne i Secretary og faktureres separat fra Marketplace-kjøpet. OpenAI-bruk, Statamic-lisens og eventuell egen Postmark-kostnad er ikke inkludert.',
    'faq_kicker' => 'Ja, men …',
    'faq_title' => 'Spørsmål en fornuftig utvikler ville stilt.',
    'faq_1_q' => 'Kan Secretary endre templates eller kode?',
    'faq_1_a' => 'Nei. Addonen er med vilje begrenset til Statamic-innhold. Den har ingen shell-tilgang og ingen generell filskriver.',
    'faq_2_q' => 'Publiserer AI-en av seg selv?',
    'faq_2_a' => 'Nei. Den lager utkast. Publisering er en separat handling som krever en uttrykkelig beskjed og redaktørens vanlige Statamic-rettigheter.',
    'faq_3_q' => 'Må jeg bruke secretary@statamic.no?',
    'faq_3_a' => 'Nei. Addonen kan bruke din egen Postmark-server og avsender. Den driftede innboksen er bare den raskeste veien til e-postflyten.',
    'faq_4_q' => 'Trenger jeg min egen OpenAI-nøkkel?',
    'faq_4_a' => 'Ja. Du legger prosjektets OpenAI-nøkkel i miljøet og beholder kontroll over bruk og kostnader.',
    'faq_5_q' => 'Fungerer dette med mine egne blueprints?',
    'faq_5_a' => 'Det er poenget. Secretary leser de faktiske blueprintene og sender bare feltverdier som passer strukturen den fant.',
    'final_kicker' => 'Gi småjobbene videre',
    'final_title' => 'Behold kontrollen. Mist køen.',
    'final_body' => 'La redaktørene be om endringer slik mennesker faktisk snakker—og la Statamic fortsette å være Statamic.',
    'final_cta' => 'Hent Secretary for $49',
    'footer_line' => 'Laget for Statamic-redaktører, utviklere og alle som bare skulle endre én liten ting.',
    'footer_marketplace' => 'Marketplace',
    'footer_github' => 'GitHub',
    'footer_privacy' => 'Personvern',
    'footer_consent' => 'Personvernvalg',
    'consent_title' => 'Kan vi telle besøket ditt?',
    'consent_body' => 'Vi bruker Google Analytics for å forstå hvilke deler av siden som er nyttige. Google-taggen lastes først hvis du sier ja.',
    'consent_accept' => 'Godta analyse',
    'consent_decline' => 'Bare nødvendige',
] : [
    'html_lang' => 'en',
    'title' => 'Secretary for Statamic – AI content assistant for Statamic',
    'description' => 'Ask for Statamic content changes by email or Control Panel chat. Secretary follows your blueprints, prepares safe drafts, and publishes only when asked.',
    'skip' => 'Skip to content',
    'nav_demo' => 'Try the demo',
    'nav_safety' => 'How it works',
    'nav_pricing' => 'Pricing',
    'nav_cta' => 'Get Secretary',
    'language' => 'Norsk',
    'eyebrow' => 'A content assistant for Statamic 6',
    'hero_title_1' => 'Your Statamic site',
    'hero_title_2' => 'just hired a secretary.',
    'hero_lead' => 'Send an email. Ask in the Control Panel. Get a structured draft that actually fits your blueprints, content, and publishing flow.',
    'hero_cta' => 'Get Secretary — $49',
    'hero_demo' => 'Watch it work',
    'hero_note' => 'One-time purchase · via Statamic Marketplace',
    'stamp_1' => 'Content only',
    'stamp_2' => 'Draft first',
    'stamp_3' => 'Human approved',
    'window_label' => 'Secretary · Control Panel',
    'window_status' => 'ready',
    'chat_1' => 'Could you update Friday hours to 4 PM and make the homepage notice a little warmer?',
    'chat_2' => 'Done. I found the opening hours and notice in the Home blueprint. Your draft is ready to review.',
    'change_label' => '2 changes · draft',
    'change_from' => 'Fri 9 AM–6 PM',
    'change_to' => 'Fri 9 AM–4 PM',
    'micro_1' => 'Fewer tiny tickets.',
    'micro_2' => 'More publishing.',
    'micro_3' => 'Less “could you just …?”',
    'problem_kicker' => 'The tiny content change',
    'problem_title' => 'Not another tiny developer ticket.',
    'problem_body' => 'Opening hours, intros, and that new child page should not wait for the next sprint. Secretary turns an editor’s instruction into a safe, visible draft—without wandering into templates, config, or code.',
    'feature_1_title' => 'Speak human. In your language.',
    'feature_1_body' => 'Write what you would send to a colleague, in the language your team uses. Secretary follows your language, finds the right content, and asks a follow-up when something important is missing.',
    'feature_2_title' => 'Keep Statamic',
    'feature_2_body' => 'Fields are validated against your real blueprints. Structure, multisite, permissions, and revisions remain part of the job.',
    'feature_3_title' => 'Look before you launch',
    'feature_3_body' => 'Every change starts as a draft. Editors can refine it in chat and publish only when they say so explicitly.',
    'demo_kicker' => 'Try it right here',
    'demo_title' => 'One message. A proper draft.',
    'demo_body' => 'This is a small simulation, but the workflow is the real thing: Secretary reads the structure, proposes the change, and lets you decide.',
    'demo_email' => 'Email',
    'demo_cp' => 'Control Panel',
    'demo_to' => 'To',
    'demo_subject' => 'Subject',
    'demo_subject_value' => 'A tiny homepage change',
    'demo_prompt_label' => 'Instruction',
    'demo_prompt_1' => 'Update Friday hours to 4 PM and make the homepage notice a little warmer.',
    'demo_prompt_2' => 'Create a Business advisory page under Services. Keep it as a draft.',
    'demo_prompt_3' => 'Tighten the About intro. Keep the tone, but remove the repetition.',
    'demo_chip_1' => 'Opening hours',
    'demo_chip_2' => 'New page',
    'demo_chip_3' => 'Polish copy',
    'demo_send' => 'Send to Secretary',
    'demo_running' => 'Secretary is working …',
    'demo_reset' => 'Try another',
    'demo_step_1' => 'Instruction received',
    'demo_step_2' => 'Reading blueprint and current content',
    'demo_step_3' => 'Checking fields and permissions',
    'demo_step_4' => 'Draft ready',
    'demo_result_title' => 'Ready for review',
    'demo_result_body' => 'Secretary changed only the two fields you asked for. Nothing has been published.',
    'demo_before' => 'Before',
    'demo_after' => 'Draft',
    'demo_old_notice' => 'We close early on Friday.',
    'demo_new_notice' => 'We’re wrapping up a little earlier this Friday. Come see us before 4!',
    'demo_result_note' => 'Review. Refine. Publish. That’s the whole thing.',
    'how_kicker' => 'Two doors. Same careful work.',
    'how_title' => 'Work where you already work.',
    'email_title' => 'Send an email',
    'email_body' => 'Reply in the same thread to refine the draft. When you ask to publish, Secretary checks permissions and makes sure nobody changed the content in the meantime.',
    'cp_title' => 'Open chat in Statamic',
    'cp_body' => 'The assistant is available across the Control Panel. Drafts and status update in place without a full page refresh.',
    'shared_title' => 'Rather skip the Postmark setup?',
    'shared_body' => 'Pair your site with the hosted inbox and get a dedicated address such as example.com@statamic.no. The sender and installation are securely matched, so the right request always reaches the right site.',
    'safety_kicker' => 'Friendly with content. Paranoid about boundaries.',
    'safety_title' => 'It can do plenty. Not everything.',
    'safety_body' => 'Secretary gets no shell, generic file writer, or free rein over your project. It gets narrow Statamic tools that respect the content root, your blueprints, and the editor’s own permissions.',
    'guard_1' => 'No code, templates, or config',
    'guard_2' => 'No deleting or arbitrary file access',
    'guard_3' => 'No publishing without an explicit request',
    'guard_4' => 'No overwriting newer human changes',
    'guard_5' => 'A complete conversation and change trail',
    'quote' => 'It does not vibe-code its way through your YAML.',
    'pricing_kicker' => 'A small price for fewer small things',
    'pricing_title' => 'Buy the addon. Pick your inbox.',
    'addon_label' => 'Statamic addon',
    'addon_price' => '$49',
    'addon_period' => 'one time',
    'addon_body' => 'Everything you need to work with Secretary in the Control Panel and through your own Postmark server.',
    'addon_feature_1' => 'Global Control Panel chat',
    'addon_feature_2' => 'Structured, reviewable drafts',
    'addon_feature_3' => 'Your own Postmark server and address',
    'addon_feature_4' => 'Updates for the purchased major version',
    'addon_cta' => 'Buy on the Marketplace',
    'hosted_badge' => 'Easiest setup',
    'hosted_label' => 'Hosted inbox',
    'hosted_price' => '+$49',
    'hosted_period' => 'per year',
    'hosted_body' => 'Get example.com@statamic.no and skip your own Postmark server, inbound webhook, and mailbox.',
    'hosted_feature_1' => 'A managed address for your site',
    'hosted_feature_2' => 'Secure sender-to-site pairing',
    'hosted_feature_3' => 'Hosting, routing, and retries included',
    'hosted_feature_4' => 'Free to try on the demo',
    'hosted_cta' => 'Get the addon and activate',
    'pricing_note' => 'Relay is activated through secure Stripe checkout inside Secretary and billed separately from the Marketplace purchase. OpenAI usage, your Statamic license, and any bring-your-own Postmark fees are not included.',
    'faq_kicker' => 'Yes, but …',
    'faq_title' => 'Questions a sensible developer would ask.',
    'faq_1_q' => 'Can Secretary edit templates or code?',
    'faq_1_a' => 'No. The addon is intentionally limited to Statamic content. It has no shell access and no generic file-writing tool.',
    'faq_2_q' => 'Does the AI publish on its own?',
    'faq_2_a' => 'No. It prepares drafts. Publishing is a separate action that requires an explicit instruction and the editor’s normal Statamic permission.',
    'faq_3_q' => 'Do I have to use secretary@statamic.no?',
    'faq_3_a' => 'No. The addon can use your own Postmark server and address. The hosted inbox is simply the quickest route to the email workflow.',
    'faq_4_q' => 'Do I need my own OpenAI key?',
    'faq_4_a' => 'Yes. You add your project’s OpenAI key to the environment and stay in control of usage and cost.',
    'faq_5_q' => 'Will this work with my custom blueprints?',
    'faq_5_a' => 'That is the point. Secretary reads the real blueprints and submits only field values that fit the structure it found.',
    'final_kicker' => 'Delegate the tiny stuff',
    'final_title' => 'Keep the control. Lose the queue.',
    'final_body' => 'Let editors request changes the way humans actually speak—and let Statamic keep being Statamic.',
    'final_cta' => 'Get Secretary for $49',
    'footer_line' => 'Made for Statamic editors, developers, and everyone who only needed one tiny change.',
    'footer_marketplace' => 'Marketplace',
    'footer_github' => 'GitHub',
    'footer_privacy' => 'Privacy',
    'footer_consent' => 'Privacy choices',
    'consent_title' => 'May we count your visit?',
    'consent_body' => 'We use Google Analytics to understand which parts of this site are useful. The Google tag loads only after you say yes.',
    'consent_accept' => 'Accept analytics',
    'consent_decline' => 'Necessary only',
];

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$featureKeys = [1, 2, 3];
$guardKeys = [1, 2, 3, 4, 5];
$addonFeatureKeys = [1, 2, 3, 4];
$hostedFeatureKeys = [1, 2, 3, 4];
$faqKeys = [1, 2, 3, 4, 5];
$organizationSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    '@id' => 'https://secretary.statamic.no/#organization',
    'name' => 'Secretary for Statamic',
    'url' => 'https://secretary.statamic.no/',
    'logo' => 'https://secretary.statamic.no/assets/secretary-icon.png',
    'email' => 'kontakt@prototypen.no',
    'sameAs' => [
        'https://github.com/axelferdinand/statamic-secretary',
    ],
];
$softwareSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    '@id' => 'https://secretary.statamic.no/#software',
    'name' => 'Secretary',
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Statamic 6',
    'description' => $copy['description'],
    'url' => $siteUrl,
    'image' => $ogImageUrl,
    'publisher' => ['@id' => 'https://secretary.statamic.no/#organization'],
    'offers' => [
        [
            '@type' => 'Offer',
            'name' => 'Secretary addon',
            'price' => '49',
            'priceCurrency' => 'USD',
            'url' => $marketplaceUrl,
        ],
        [
            '@type' => 'Offer',
            'name' => 'Hosted Secretary inbox',
            'price' => '49',
            'priceCurrency' => 'USD',
            'url' => $siteUrl.'#pricing',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => '49',
                'priceCurrency' => 'USD',
                'unitText' => 'YEAR',
            ],
        ],
    ],
];
$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(
        static fn (int $index): array => [
            '@type' => 'Question',
            'name' => $copy["faq_{$index}_q"],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $copy["faq_{$index}_a"],
            ],
        ],
        $faqKeys,
    ),
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
    <link rel="alternate" hreflang="en" href="https://secretary.statamic.no/">
    <link rel="alternate" hreflang="nb" href="https://secretary.statamic.no/no">
    <link rel="alternate" hreflang="x-default" href="https://secretary.statamic.no/">
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
    <meta property="og:locale:alternate" content="<?= $isNorwegian ? 'en_US' : 'nb_NO' ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $e($copy['title']) ?>">
    <meta name="twitter:description" content="<?= $e($copy['description']) ?>">
    <meta name="twitter:image" content="<?= $e($ogImageUrl) ?>">
    <meta name="twitter:image:alt" content="<?= $e($copy['title']) ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/secretary-icon.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/assets/secretary-icon.png">
    <link rel="privacy-policy" href="<?= $e($privacyUrl) ?>">
    <?php if ($analyticsMeasurementId !== null) { ?>
        <meta name="google-analytics-id" content="<?= $e($analyticsMeasurementId) ?>">
    <?php } ?>
    <link rel="stylesheet" href="/assets/secretary.css">
    <script src="/assets/secretary.js" defer></script>
    <script type="application/ld+json"><?= json_encode($organizationSchema, $jsonFlags) ?></script>
    <script type="application/ld+json"><?= json_encode($softwareSchema, $jsonFlags) ?></script>
    <script type="application/ld+json"><?= json_encode($faqSchema, $jsonFlags) ?></script>
</head>
<body>
    <a class="skip-link" href="#main"><?= $e($copy['skip']) ?></a>

    <header class="site-header" data-header>
        <div class="shell nav">
            <a class="brand" href="<?= $isNorwegian ? '/no' : '/' ?>" aria-label="Secretary for Statamic">
                <img src="/assets/secretary-icon.png" alt="" width="52" height="52">
                <span><strong>Secretary</strong> for Statamic</span>
            </a>

            <nav class="nav-links" aria-label="Primary">
                <a href="#demo"><?= $e($copy['nav_demo']) ?></a>
                <a href="#safety"><?= $e($copy['nav_safety']) ?></a>
                <a href="#pricing"><?= $e($copy['nav_pricing']) ?></a>
            </nav>

            <div class="nav-actions">
                <a class="language-link" href="<?= $isNorwegian ? '/' : '/no' ?>" lang="<?= $isNorwegian ? 'en' : 'nb' ?>">
                    <?= $e($copy['language']) ?>
                </a>
                <a class="button button-small button-mint" href="<?= $e($marketplaceUrl) ?>">
                    <?= $e($copy['nav_cta']) ?>
                    <span aria-hidden="true">↗</span>
                </a>
            </div>
        </div>
    </header>

    <main id="main">
        <section class="hero">
            <div class="hero-orbit hero-orbit-one" aria-hidden="true"></div>
            <div class="hero-orbit hero-orbit-two" aria-hidden="true"></div>

            <div class="shell hero-grid">
                <div class="hero-copy" data-reveal>
                    <p class="eyebrow"><span></span><?= $e($copy['eyebrow']) ?></p>
                    <h1>
                        <span><?= $e($copy['hero_title_1']) ?></span>
                        <em><?= $e($copy['hero_title_2']) ?></em>
                    </h1>
                    <p class="hero-lead"><?= $e($copy['hero_lead']) ?></p>

                    <div class="hero-actions">
                        <a class="button button-coral" href="<?= $e($marketplaceUrl) ?>">
                            <?= $e($copy['hero_cta']) ?>
                            <span aria-hidden="true">↗</span>
                        </a>
                        <a class="text-link" href="#demo">
                            <span class="play" aria-hidden="true">▶</span>
                            <?= $e($copy['hero_demo']) ?>
                        </a>
                    </div>
                    <p class="hero-note"><?= $e($copy['hero_note']) ?></p>

                    <ul class="trust-row" aria-label="Product principles">
                        <li><span aria-hidden="true">✓</span><?= $e($copy['stamp_1']) ?></li>
                        <li><span aria-hidden="true">✓</span><?= $e($copy['stamp_2']) ?></li>
                        <li><span aria-hidden="true">✓</span><?= $e($copy['stamp_3']) ?></li>
                    </ul>
                </div>

                <div class="hero-product" data-reveal>
                    <div class="secretary-window">
                        <div class="window-bar">
                            <div class="window-dots" aria-hidden="true"><span></span><span></span><span></span></div>
                            <p><?= $e($copy['window_label']) ?></p>
                            <span class="status"><i></i><?= $e($copy['window_status']) ?></span>
                        </div>
                        <div class="window-body">
                            <div class="message message-user">
                                <span class="avatar avatar-user" aria-hidden="true">AF</span>
                                <p><?= $e($copy['chat_1']) ?></p>
                            </div>
                            <div class="message message-secretary">
                                <img src="/assets/secretary-icon.png" alt="" width="42" height="42">
                                <div>
                                    <p><?= $e($copy['chat_2']) ?></p>
                                    <div class="change-preview">
                                        <div class="change-preview-head">
                                            <span><?= $e($copy['change_label']) ?></span>
                                            <i aria-hidden="true">✓</i>
                                        </div>
                                        <div class="change-row">
                                            <del><?= $e($copy['change_from']) ?></del>
                                            <span aria-hidden="true">→</span>
                                            <ins><?= $e($copy['change_to']) ?></ins>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="composer-mock" aria-hidden="true">
                                <span>Aa</span>
                                <i></i>
                                <b>↑</b>
                            </div>
                        </div>
                    </div>
                    <div class="hero-tag hero-tag-top" aria-hidden="true">content/</div>
                    <div class="hero-tag hero-tag-bottom" aria-hidden="true">draft ✓</div>
                </div>
            </div>
        </section>

        <div class="ticker" aria-label="Product outcomes">
            <div class="ticker-track">
                <?php for ($repeat = 0; $repeat < 2; $repeat++) { ?>
                    <span><?= $e($copy['micro_1']) ?></span><i>✦</i>
                    <span><?= $e($copy['micro_2']) ?></span><i>✦</i>
                    <span><?= $e($copy['micro_3']) ?></span><i>✦</i>
                <?php } ?>
            </div>
        </div>

        <section class="section problem">
            <div class="shell">
                <div class="section-heading section-heading-wide" data-reveal>
                    <p class="kicker"><?= $e($copy['problem_kicker']) ?></p>
                    <h2><?= $e($copy['problem_title']) ?></h2>
                    <p><?= $e($copy['problem_body']) ?></p>
                </div>

                <div class="feature-grid">
                    <?php foreach ($featureKeys as $index) { ?>
                        <article class="feature-card feature-card-<?= $index ?>" data-reveal>
                            <span class="feature-number">0<?= $index ?></span>
                            <div class="feature-icon" aria-hidden="true">
                                <?= $index === 1 ? '“Aa”' : ($index === 2 ? '{…}' : '✓') ?>
                            </div>
                            <h3><?= $e($copy["feature_{$index}_title"]) ?></h3>
                            <p><?= $e($copy["feature_{$index}_body"]) ?></p>
                        </article>
                    <?php } ?>
                </div>
            </div>
        </section>

        <section class="section demo-section" id="demo">
            <div class="shell demo-layout">
                <div class="demo-intro" data-reveal>
                    <p class="kicker"><?= $e($copy['demo_kicker']) ?></p>
                    <h2><?= $e($copy['demo_title']) ?></h2>
                    <p><?= $e($copy['demo_body']) ?></p>
                    <div class="demo-arrow" aria-hidden="true">↘</div>
                </div>

                <div class="demo-card" data-demo data-reveal>
                    <div class="demo-tabs" role="tablist" aria-label="Demo channel">
                        <button type="button" class="is-active" role="tab" aria-selected="true" data-demo-channel="email">
                            <span aria-hidden="true">✉</span><?= $e($copy['demo_email']) ?>
                        </button>
                        <button type="button" role="tab" aria-selected="false" data-demo-channel="cp">
                            <span aria-hidden="true">⌘</span><?= $e($copy['demo_cp']) ?>
                        </button>
                    </div>

                    <div class="demo-compose" data-demo-compose>
                        <div class="email-fields" data-email-fields>
                            <p><span><?= $e($copy['demo_to']) ?></span><strong>secretary@statamic.no</strong></p>
                            <p><span><?= $e($copy['demo_subject']) ?></span><strong><?= $e($copy['demo_subject_value']) ?></strong></p>
                        </div>

                        <label for="demo-prompt"><?= $e($copy['demo_prompt_label']) ?></label>
                        <textarea id="demo-prompt" rows="5" data-demo-prompt><?= $e($copy['demo_prompt_1']) ?></textarea>

                        <div class="prompt-chips" aria-label="Example instructions">
                            <button type="button" class="is-active" data-demo-example="1"><?= $e($copy['demo_chip_1']) ?></button>
                            <button type="button" data-demo-example="2"><?= $e($copy['demo_chip_2']) ?></button>
                            <button type="button" data-demo-example="3"><?= $e($copy['demo_chip_3']) ?></button>
                        </div>

                        <button class="button button-coral demo-submit" type="button" data-demo-submit>
                            <span data-demo-submit-label><?= $e($copy['demo_send']) ?></span>
                            <span aria-hidden="true">→</span>
                        </button>
                    </div>

                    <div class="demo-progress" data-demo-progress hidden>
                        <div class="progress-head">
                            <img src="/assets/secretary-icon.png" alt="" width="54" height="54">
                            <div>
                                <p>Secretary</p>
                                <strong data-demo-status aria-live="polite"><?= $e($copy['demo_running']) ?></strong>
                            </div>
                            <span class="working-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        </div>
                        <ol>
                            <?php for ($index = 1; $index <= 4; $index++) { ?>
                                <li data-demo-step="<?= $index ?>">
                                    <span aria-hidden="true"><?= $index ?></span>
                                    <p><?= $e($copy["demo_step_{$index}"]) ?></p>
                                    <i aria-hidden="true">✓</i>
                                </li>
                            <?php } ?>
                        </ol>
                    </div>

                    <div class="demo-result" data-demo-result hidden>
                        <div class="result-heading">
                            <span class="result-check" aria-hidden="true">✓</span>
                            <div>
                                <p><?= $e($copy['demo_result_title']) ?></p>
                                <span><?= $e($copy['demo_result_body']) ?></span>
                            </div>
                        </div>

                        <div class="result-columns">
                            <div>
                                <p class="result-label"><?= $e($copy['demo_before']) ?></p>
                                <strong><?= $e($copy['change_from']) ?></strong>
                                <p><?= $e($copy['demo_old_notice']) ?></p>
                            </div>
                            <div class="result-after">
                                <p class="result-label"><?= $e($copy['demo_after']) ?></p>
                                <strong><?= $e($copy['change_to']) ?></strong>
                                <p><?= $e($copy['demo_new_notice']) ?></p>
                            </div>
                        </div>

                        <div class="result-footer">
                            <p><?= $e($copy['demo_result_note']) ?></p>
                            <button type="button" class="text-button" data-demo-reset><?= $e($copy['demo_reset']) ?> ↺</button>
                        </div>
                    </div>

                    <div class="demo-prompt-data" hidden
                         data-prompt-1="<?= $e($copy['demo_prompt_1']) ?>"
                         data-prompt-2="<?= $e($copy['demo_prompt_2']) ?>"
                         data-prompt-3="<?= $e($copy['demo_prompt_3']) ?>"
                         data-running="<?= $e($copy['demo_running']) ?>"></div>
                </div>
            </div>
        </section>

        <section class="section channels">
            <div class="shell">
                <div class="section-heading" data-reveal>
                    <p class="kicker"><?= $e($copy['how_kicker']) ?></p>
                    <h2><?= $e($copy['how_title']) ?></h2>
                </div>

                <div class="channel-grid">
                    <article class="channel-card channel-email" data-reveal>
                        <div class="channel-illustration email-illustration" aria-hidden="true">
                            <span>To: secretary@statamic.no</span>
                            <i></i>
                            <b>Send →</b>
                        </div>
                        <div>
                            <span class="channel-index">01 / EMAIL</span>
                            <h3><?= $e($copy['email_title']) ?></h3>
                            <p><?= $e($copy['email_body']) ?></p>
                        </div>
                    </article>

                    <article class="channel-card channel-cp" data-reveal>
                        <div class="channel-illustration cp-illustration" aria-hidden="true">
                            <div><i></i><i></i><i></i></div>
                            <p>Secretary</p>
                            <span>Draft ready <b>✓</b></span>
                        </div>
                        <div>
                            <span class="channel-index">02 / CONTROL PANEL</span>
                            <h3><?= $e($copy['cp_title']) ?></h3>
                            <p><?= $e($copy['cp_body']) ?></p>
                        </div>
                    </article>
                </div>

                <aside class="shared-callout" data-reveal>
                    <span class="shared-icon" aria-hidden="true">@</span>
                    <div>
                        <h3><?= $e($copy['shared_title']) ?></h3>
                        <p><?= $e($copy['shared_body']) ?></p>
                    </div>
                    <a href="#pricing"><?= $e($copy['nav_pricing']) ?> <span aria-hidden="true">↓</span></a>
                </aside>
            </div>
        </section>

        <section class="section safety" id="safety">
            <div class="safety-grid shell">
                <div class="safety-copy" data-reveal>
                    <p class="kicker"><?= $e($copy['safety_kicker']) ?></p>
                    <h2><?= $e($copy['safety_title']) ?></h2>
                    <p><?= $e($copy['safety_body']) ?></p>
                    <blockquote>“<?= $e($copy['quote']) ?>”</blockquote>
                </div>

                <div class="guard-list" data-reveal>
                    <?php foreach ($guardKeys as $index) { ?>
                        <div>
                            <span aria-hidden="true">✓</span>
                            <p><?= $e($copy["guard_{$index}"]) ?></p>
                        </div>
                    <?php } ?>
                    <div class="guard-boundary" aria-hidden="true">
                        <code>content/</code>
                        <span>inside the line</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="section pricing" id="pricing">
            <div class="shell">
                <div class="section-heading section-heading-centered" data-reveal>
                    <p class="kicker"><?= $e($copy['pricing_kicker']) ?></p>
                    <h2><?= $e($copy['pricing_title']) ?></h2>
                </div>

                <div class="pricing-grid">
                    <article class="price-card price-addon" data-reveal>
                        <div class="price-top">
                            <p><?= $e($copy['addon_label']) ?></p>
                            <div class="price">
                                <strong><?= $e($copy['addon_price']) ?></strong>
                                <span><?= $e($copy['addon_period']) ?></span>
                            </div>
                            <p><?= $e($copy['addon_body']) ?></p>
                        </div>
                        <ul>
                            <?php foreach ($addonFeatureKeys as $index) { ?>
                                <li><span aria-hidden="true">✓</span><?= $e($copy["addon_feature_{$index}"]) ?></li>
                            <?php } ?>
                        </ul>
                        <a class="button button-dark button-full" href="<?= $e($marketplaceUrl) ?>">
                            <?= $e($copy['addon_cta']) ?><span aria-hidden="true">↗</span>
                        </a>
                    </article>

                    <article class="price-card price-hosted" data-reveal>
                        <span class="price-badge"><?= $e($copy['hosted_badge']) ?></span>
                        <div class="price-top">
                            <p><?= $e($copy['hosted_label']) ?></p>
                            <div class="price">
                                <strong><?= $e($copy['hosted_price']) ?></strong>
                                <span><?= $e($copy['hosted_period']) ?></span>
                            </div>
                            <p><?= $e($copy['hosted_body']) ?></p>
                        </div>
                        <ul>
                            <?php foreach ($hostedFeatureKeys as $index) { ?>
                                <li><span aria-hidden="true">✓</span><?= $e($copy["hosted_feature_{$index}"]) ?></li>
                            <?php } ?>
                        </ul>
                        <a class="button button-mint button-full" href="<?= $e($marketplaceUrl) ?>">
                            <?= $e($copy['hosted_cta']) ?><span aria-hidden="true">↗</span>
                        </a>
                    </article>
                </div>
                <p class="pricing-note"><?= $e($copy['pricing_note']) ?></p>
            </div>
        </section>

        <section class="section faq">
            <div class="shell faq-grid">
                <div class="faq-heading" data-reveal>
                    <p class="kicker"><?= $e($copy['faq_kicker']) ?></p>
                    <h2><?= $e($copy['faq_title']) ?></h2>
                </div>
                <div class="faq-list" data-reveal>
                    <?php foreach ($faqKeys as $index) { ?>
                        <details>
                            <summary>
                                <span><?= $e($copy["faq_{$index}_q"]) ?></span>
                                <i aria-hidden="true"></i>
                            </summary>
                            <p><?= $e($copy["faq_{$index}_a"]) ?></p>
                        </details>
                    <?php } ?>
                </div>
            </div>
        </section>

        <section class="final-cta">
            <div class="final-orbit" aria-hidden="true"></div>
            <div class="shell final-inner" data-reveal>
                <p class="kicker"><?= $e($copy['final_kicker']) ?></p>
                <h2><?= $e($copy['final_title']) ?></h2>
                <p><?= $e($copy['final_body']) ?></p>
                <a class="button button-coral" href="<?= $e($marketplaceUrl) ?>">
                    <?= $e($copy['final_cta']) ?><span aria-hidden="true">↗</span>
                </a>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="shell footer-grid">
            <a class="brand footer-brand" href="<?= $isNorwegian ? '/no' : '/' ?>">
                <img src="/assets/secretary-icon.png" alt="" width="46" height="46">
                <span><strong>Secretary</strong> for Statamic</span>
            </a>
            <p><?= $e($copy['footer_line']) ?></p>
            <nav aria-label="Footer">
                <a href="<?= $e($marketplaceUrl) ?>" rel="noopener"><?= $e($copy['footer_marketplace']) ?></a>
                <a href="https://github.com/axelferdinand/statamic-secretary" rel="noopener"><?= $e($copy['footer_github']) ?></a>
                <a href="<?= $e($privacyUrl) ?>"><?= $e($copy['footer_privacy']) ?></a>
                <?php if ($analyticsMeasurementId !== null) { ?>
                    <button class="footer-link-button" type="button" data-consent-open><?= $e($copy['footer_consent']) ?></button>
                <?php } ?>
            </nav>
        </div>
    </footer>

    <?php if ($analyticsMeasurementId !== null) { ?>
        <section class="consent-manager" role="region" aria-labelledby="consent-title" data-consent-manager hidden>
            <div class="shell consent-inner">
                <div>
                    <p class="consent-kicker"><?= $isNorwegian ? 'Personvern' : 'Privacy' ?></p>
                    <h2 id="consent-title"><?= $e($copy['consent_title']) ?></h2>
                    <p><?= $e($copy['consent_body']) ?> <a href="<?= $e($privacyUrl) ?>"><?= $e($copy['footer_privacy']) ?> →</a></p>
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
