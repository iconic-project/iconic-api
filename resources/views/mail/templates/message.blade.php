<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
</head>
<body style="font-family: Georgia, serif; color: #1a1a14; line-height: 1.5; max-width: 560px;">
    <p style="letter-spacing: 0.18em; font-size: 12px; text-transform: uppercase;">Iconic</p>
    @foreach ($paragraphs as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
    @if (count($list) > 0)
        <ul>
            @foreach ($list as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    @endif
    @if ($ctaUrl)
        <p style="margin: 18px 0;">
            <a href="{{ $ctaUrl }}" style="display: inline-block; padding: 10px 18px; background: #2c4a3a; color: #f4efe4; text-decoration: none; letter-spacing: 0.08em; font-size: 13px;">{{ $ctaLabel }}</a>
        </p>
    @endif
    <p style="margin-top: 28px; font-size: 13px; color: #5a5a4a;">
        ICONIC · Intimate Yacht Expeditions · {{ $replyTo }}
        @if ($reference)
            · Reference {{ $reference }}
        @endif
    </p>
</body>
</html>
