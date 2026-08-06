<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statamic Secretary</title>
</head>
<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
    <div style="max-width:640px;margin:0 auto;padding:32px 20px">
        <div style="border:1px solid #e4e4e7;border-radius:12px;background:#ffffff;padding:28px">
            <div style="margin-bottom:18px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#71717a">Statamic Secretary</div>
            <div style="font-size:20px;font-weight:700;line-height:1.35">{{ $title }}</div>
            <div style="margin-top:12px;font-size:16px;line-height:1.65">{{ $body }}</div>
        </div>
        <p style="margin:16px 4px 0;font-size:12px;line-height:1.5;color:#71717a">{{ $replyInstruction }}</p>
    </div>
</body>
</html>
