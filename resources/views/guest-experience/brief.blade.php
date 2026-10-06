<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Arrivals brief</title>
    <style>
        /* DOCUMENT_FONTS */
        @page { size: A4; margin: 12mm 13mm; }
        body { font-family: Archivo, sans-serif; font-size: 11px; line-height: 1.45; color: #202B26; background: #FAF9F0; margin: 0; }
        .paper { width: 100%; }
        .dh { width: 100%; border-collapse: collapse; border-bottom: 2px solid #202B26; margin-bottom: 12px; }
        .dh td { vertical-align: top; padding-bottom: 10px; }
        .dlogo { font-family: Oswald, sans-serif; letter-spacing: 0.35em; font-size: 18px; }
        .dtag { font-size: 10px; color: #6b6b5a; letter-spacing: 0.06em; }
        .dtitle { font-family: Oswald, sans-serif; letter-spacing: 0.16em; font-size: 13px; text-align: right; }
        .dsub { font-size: 10.5px; color: #585940; line-height: 1.6; text-align: right; }
        .dnote { font-size: 10.5px; color: #6b6b5a; margin: 8px 0; }
        .dsec { font-family: "IBM Plex Mono", monospace; font-size: 9px; letter-spacing: 0.14em; text-transform: uppercase; color: #6b6b5a; margin: 16px 0 6px; border-bottom: 1px solid #202B26; padding-bottom: 4px; }
        .dkv { display: table; width: 100%; padding: 3px 0; font-size: 11px; }
        .dkv span { display: table-cell; }
        .dkv span:first-child { width: 46%; color: #585940; }
    </style>
</head>
<body>
<div class="paper">
    <table class="dh">
        <tr>
            <td>
                <div class="dlogo">ICONIC</div>
                <div class="dtag">GUEST EXPERIENCE</div>
            </td>
            <td>
                <div class="dtitle">ARRIVALS BRIEF</div>
                <div class="dsub">{{ $property }} · {{ $arrivalDate }} · {{ $guests }} guests · {{ $answered }} questionnaires</div>
            </td>
        </tr>
    </table>

    @foreach ($sections as $section)
        <div class="dsec">{{ $section['label'] }}</div>
        @if ($section['rows'] === [])
            <div class="dnote">None recorded.</div>
        @else
            @foreach ($section['rows'] as $row)
                <div class="dkv"><span>{{ $row['who'] }}</span><span>{{ $row['value'] }}</span></div>
            @endforeach
        @endif
    @endforeach

    <div class="dsec">Room &amp; rhythm</div>
    @foreach ($counts as $label => $value)
        <div class="dkv"><span>{{ $label }}</span><span>{{ $value }}</span></div>
    @endforeach

    <div class="dnote" style="margin-top:12px">{{ $unanswered }} guest(s) have not answered yet.</div>
</div>
</body>
</html>
