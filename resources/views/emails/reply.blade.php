<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statamic Secretary</title>
</head>
<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px">
        <div style="border:1px solid #e4e4e7;border-radius:12px;background:#ffffff;padding:28px">
            <div style="margin-bottom:18px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#71717a">Statamic Secretary</div>
            <div style="font-size:16px;line-height:1.65;white-space:pre-wrap">{{ $body }}</div>
            @if (count($changeSets))
                <div style="margin-top:22px;border-top:1px solid #e4e4e7;padding-top:18px">
                    <div style="margin-bottom:8px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#71717a">Endringer i denne meldingen</div>
                    <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">
                        @foreach ($changeSets as $changeSet)
                            <li>{{ $changeSet['summary'] }} — {{ $changeSet['status'] === 'published' ? 'publisert' : 'utkast' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div style="margin-top:24px">
                <a href="{{ $reviewUrl }}" style="display:inline-block;border-radius:8px;background:#2563eb;color:#ffffff;padding:11px 16px;font-size:14px;font-weight:700;text-decoration:none">Se samtale og utkast</a>
            </div>
        </div>
        <p style="margin:16px 4px 0;font-size:12px;line-height:1.5;color:#71717a">Svar på denne e-posten for å fortsette samme samtale.</p>
    </div>
</body>
</html>
