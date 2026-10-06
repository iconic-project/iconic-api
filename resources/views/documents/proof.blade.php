<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $snapshot['title'] ?? 'Proof' }}</title>
    <style>
        @page { margin: 12mm 13mm; }
        body { font-family: Archivo, sans-serif; font-size: 12px; line-height: 1.55; color: #202B26; background: #FAF9F0; }
        .paper { width: 100%; }
        .dh { width: 100%; border-collapse: collapse; border-bottom: 2px solid #202B26; margin-bottom: 16px; }
        .dh td { vertical-align: top; padding-bottom: 14px; }
        .dlogo { font-family: Oswald, sans-serif; letter-spacing: 0.35em; font-size: 20px; }
        .dtag { font-size: 10px; color: #6b6b5a; letter-spacing: 0.06em; }
        .dtitle { font-family: Oswald, sans-serif; letter-spacing: 0.16em; font-size: 14px; text-align: right; }
        .dsub { font-size: 10.5px; color: #585940; line-height: 1.6; text-align: right; }
        .line { font-family: "IBM Plex Mono", monospace; font-size: 11px; }
    </style>
</head>
<body>
<div class="paper">
    <table class="dh">
        <tr>
            <td>
                <div class="dlogo">ICONIC</div>
                <div class="dtag">Reservations</div>
            </td>
            <td>
                <div class="dtitle">{{ $snapshot['title'] ?? 'Proof' }}</div>
                <div class="dsub">{{ $snapshot['subtitle'] ?? '' }}</div>
            </td>
        </tr>
    </table>
    <p class="line">{{ $snapshot['line'] ?? '' }}</p>
</div>
</body>
</html>
