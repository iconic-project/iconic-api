<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $delivery->subject }}</title>
</head>
<body style="font-family: Georgia, serif; color: #1a1a14; line-height: 1.5; max-width: 560px;">
    <p style="letter-spacing: 0.18em; font-size: 12px; text-transform: uppercase;">Iconic</p>
    @yield('body')
    <p style="margin-top: 28px; font-size: 13px; color: #5a5a4a;">
        ICONIC · Intimate Yacht Expeditions · {{ $replyTo }}
        @if ($booking->displayReference())
            · Reference {{ $booking->displayReference() }}
        @endif
    </p>
</body>
</html>
