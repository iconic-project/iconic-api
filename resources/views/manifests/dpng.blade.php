@extends('manifests.layout')

@section('title', 'DPNG passenger list')

@section('body')
    <table class="dh">
        <tr>
            <td>
                <div class="dlogo">ICONIC</div>
                <div class="dtag">INTIMATE YACHT EXPEDITIONS</div>
            </td>
            <td>
                <div class="dtitle">DPNG PASSENGER LIST</div>
                <div class="dsub">
                    {{ $departure->property->name }} · {{ $departureDate }} → {{ $returnDate }}<br>
                    Due {{ \Carbon\CarbonImmutable::parse($due->dpng)->format('j M Y') }}
                    ({{ $due->charter ? 'charter' : 'FIT/groups' }} T−{{ $due->dpngDays }})
                </div>
            </td>
        </tr>
    </table>
    <div class="dnote">Column set approved by Iconic, 12 Sep 2026.</div>
    <table class="dt">
        <thead>
            <tr>
                <th>#</th>
                <th>Surname</th>
                <th>Given names</th>
                <th>Nationality</th>
                <th>Passport</th>
                <th>Expiry</th>
                <th>DOB</th>
                <th>Age</th>
                <th>Cabin</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($passengers as $passenger)
                @php($cells = $passenger->dpngCells())
                <tr @class(['miss' => ! $passenger->complete()])>
                    @foreach ($cells as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="dnote">{{ $complete }} of {{ $passengers->count() }} passengers complete.</div>
@endsection
