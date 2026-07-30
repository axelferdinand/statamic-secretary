<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

use AxelFerdinand\StatamicSecretaryRelay\Data\HttpResult;
use RuntimeException;

final class LandingPage
{
    public function __construct(
        private readonly ?string $analyticsMeasurementId = null,
    ) {}

    public function render(string $locale): HttpResult
    {
        return $this->renderTemplate('landing.php', $locale);
    }

    public function privacy(string $locale): HttpResult
    {
        return $this->renderTemplate('privacy.php', $locale);
    }

    public function notFound(string $locale): HttpResult
    {
        return $this->renderTemplate(
            'not-found.php',
            $locale,
            404,
            'no-store',
            ['X-Robots-Tag' => 'noindex, nofollow'],
            false,
        );
    }

    public function robots(): HttpResult
    {
        return new HttpResult(
            200,
            implode("\n", [
                'User-agent: *',
                'Allow: /',
                'Disallow: /health',
                'Disallow: /v1/',
                '',
                'Sitemap: https://secretary.statamic.no/sitemap.xml',
                '',
            ]),
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    public function sitemap(): HttpResult
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
    <url>
        <loc>https://secretary.statamic.no/</loc>
        <xhtml:link rel="alternate" hreflang="en" href="https://secretary.statamic.no/"/>
        <xhtml:link rel="alternate" hreflang="nb" href="https://secretary.statamic.no/no"/>
        <xhtml:link rel="alternate" hreflang="x-default" href="https://secretary.statamic.no/"/>
    </url>
    <url>
        <loc>https://secretary.statamic.no/no</loc>
        <xhtml:link rel="alternate" hreflang="en" href="https://secretary.statamic.no/"/>
        <xhtml:link rel="alternate" hreflang="nb" href="https://secretary.statamic.no/no"/>
        <xhtml:link rel="alternate" hreflang="x-default" href="https://secretary.statamic.no/"/>
    </url>
    <url>
        <loc>https://secretary.statamic.no/privacy</loc>
        <xhtml:link rel="alternate" hreflang="en" href="https://secretary.statamic.no/privacy"/>
        <xhtml:link rel="alternate" hreflang="nb" href="https://secretary.statamic.no/no/personvern"/>
        <xhtml:link rel="alternate" hreflang="x-default" href="https://secretary.statamic.no/privacy"/>
    </url>
    <url>
        <loc>https://secretary.statamic.no/no/personvern</loc>
        <xhtml:link rel="alternate" hreflang="en" href="https://secretary.statamic.no/privacy"/>
        <xhtml:link rel="alternate" hreflang="nb" href="https://secretary.statamic.no/no/personvern"/>
        <xhtml:link rel="alternate" hreflang="x-default" href="https://secretary.statamic.no/privacy"/>
    </url>
</urlset>
XML;

        return new HttpResult(
            200,
            $body,
            [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    /** @param array<string, string> $headers */
    private function renderTemplate(
        string $templateName,
        string $locale,
        int $status = 200,
        string $cacheControl = 'public, max-age=300, stale-while-revalidate=86400',
        array $headers = [],
        bool $analyticsAllowed = true,
    ): HttpResult {
        $locale = $locale === 'nb' ? 'nb' : 'en';
        $analyticsMeasurementId = $analyticsAllowed
            ? $this->resolvedAnalyticsMeasurementId()
            : null;
        $template = dirname(__DIR__).'/resources/'.$templateName;

        if (! is_file($template)) {
            throw new RuntimeException('Landing page template is missing.');
        }

        ob_start();

        try {
            require $template;
            $body = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return new HttpResult(
            $status,
            $body,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => $cacheControl,
                'Content-Language' => $locale,
                'Content-Security-Policy' => $this->contentSecurityPolicy($analyticsMeasurementId !== null),
                ...$headers,
            ],
        );
    }

    private function resolvedAnalyticsMeasurementId(): ?string
    {
        $candidate = $this->analyticsMeasurementId;

        if ($candidate === null) {
            $environmentValue = getenv('RELAY_GA_MEASUREMENT_ID');
            $candidate = is_string($environmentValue) ? $environmentValue : '';
        }

        $candidate = mb_strtoupper(trim($candidate));

        return preg_match('/^G-[A-Z0-9]{4,20}$/D', $candidate) === 1
            ? $candidate
            : null;
    }

    private function contentSecurityPolicy(bool $analyticsEnabled): string
    {
        $connectSources = $analyticsEnabled
            ? "'self' https://www.google-analytics.com https://region1.google-analytics.com https://*.google-analytics.com"
            : "'none'";
        $imageSources = $analyticsEnabled
            ? "'self' data: https://www.google-analytics.com https://*.google-analytics.com"
            : "'self' data:";
        $scriptSources = $analyticsEnabled
            ? "'self' https://www.googletagmanager.com"
            : "'self'";

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'none'",
            "connect-src {$connectSources}",
            "font-src 'self'",
            "form-action 'none'",
            "frame-ancestors 'none'",
            "img-src {$imageSources}",
            "object-src 'none'",
            "script-src {$scriptSources}",
            "style-src 'self'",
            'upgrade-insecure-requests',
        ]);
    }
}
