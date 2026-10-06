@php
    $stay = $snapshot['stay'] ?? [];
    $party = $stay['party'] ?? [];
    $room = $stay['room'] ?? null;
    $guests = $party['guests'] ?? [];
@endphp
<div class="dsec">Your stay</div>
<table class="dg2">
    <tr>
        <td>
            <table class="dkv">
                <tr><td>Property</td><td>{{ $stay['property'] ?? '' }}</td></tr>
                <tr><td>Address</td><td>{{ $stay['address'] ?? '' }}</td></tr>
                <tr><td>Phone</td><td>{{ $stay['phone'] ?? '' }}</td></tr>
                <tr><td>Check-in</td><td>{{ $stay['check_in'] ?? '' }} · {{ $stay['check_in_time'] ?? '' }}</td></tr>
                <tr><td>Check-out</td><td>{{ $stay['check_out'] ?? '' }} · {{ $stay['check_out_time'] ?? '' }}</td></tr>
            </table>
        </td>
        <td>
            <table class="dkv">
                <tr><td>Nights</td><td>{{ $stay['nights'] ?? '' }}</td></tr>
                <tr><td>Room type</td><td>{{ $stay['room_type'] ?? '' }}</td></tr>
                @if(is_array($room))
                    <tr><td>Room</td><td>{{ $room['label'] ?? $room['code'] ?? '' }}</td></tr>
                @endif
                <tr><td>Rate plan</td><td>{{ $stay['rate_plan'] ?? '' }}@if(! empty($stay['meal_plan'])) · {{ $stay['meal_plan'] }}@endif</td></tr>
                <tr><td>Party</td><td>{{ (int) ($party['adults'] ?? 0) }} adults@if(((int) ($party['children'] ?? 0)) > 0), {{ (int) $party['children'] }} children@endif</td></tr>
                @if($guests !== [])
                    <tr><td>Guests</td><td>{{ implode(' · ', $guests) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>
@if(! empty($stay['entire_property']))
    <div class="dnote">The reservation covers every room at the property.</div>
@endif
@if(($stay['nights_by_season'] ?? []) !== [])
    <div class="dsec">Nights by season</div>
    <table class="dkv">
        @foreach($stay['nights_by_season'] as $season)
            <tr>
                <td>{{ $season['name'] ?? $season['season'] ?? '' }} · {{ $season['nights'] ?? 0 }} nights</td>
                <td>{{ \App\Support\Money::formatDocument((int) ($season['amount'] ?? 0)) }}</td>
            </tr>
        @endforeach
    </table>
@endif
