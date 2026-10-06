@extends('manifests.layout')

@section('title', 'Arrivals brief')

@section('body')
    <style>
        .dsec { font-family: "IBM Plex Mono", monospace; font-size: 9px; letter-spacing: 0.14em; text-transform: uppercase; color: #6b6b5a; margin: 16px 0 6px; border-bottom: 1px solid #202B26; padding-bottom: 4px; }
        .dkv { display: table; width: 100%; padding: 3px 0; font-size: 11px; }
        .dkv span { display: table-cell; }
        .dkv span:first-child { width: 46%; color: #585940; }
    </style>
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
@endsection
