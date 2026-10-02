@extends('manifests.layout')

@section('title', "Captain's manifest")

@section('body')
    <table class="dh">
        <tr>
            <td>
                <div class="dlogo">ICONIC</div>
                <div class="dtag">INTIMATE YACHT EXPEDITIONS</div>
            </td>
            <td>
                <div class="dtitle">CAPTAIN'S MANIFEST</div>
                <div class="dsub">
                    {{ $departure->yacht->name }} · {{ $departureDate }} · issued T−{{ $due->captainDays }}
                    ({{ \Carbon\CarbonImmutable::parse($due->captain)->format('j M Y') }})
                </div>
            </td>
        </tr>
    </table>
    <table class="dt">
        <thead>
            <tr>
                <th>Cabin</th>
                <th>Passenger</th>
                <th>Nat.</th>
                <th>Age</th>
                <th>Passport</th>
                <th>Emergency contact</th>
                <th>Dietary</th>
                <th>Medical / accessibility</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($passengers as $passenger)
                @php($cells = $passenger->captainCells())
                <tr @class(['miss' => ! $passenger->complete()])>
                    <td>{{ $cells[0] }}</td>
                    <td>
                        {{ $cells[1] }}
                        <div class="ref">{{ $passenger->guest->booking->reference }}</div>
                    </td>
                    @foreach (array_slice($cells, 2) as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="dnote">Confidential — contains passport and health data. Operations team and captain only.</div>
@endsection
