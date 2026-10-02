@php $cruise = $snapshot['cruise'] ?? []; @endphp
<div class="dsec">Cruise details</div>
<table class="dg2">
    <tr>
        <td>
            <table class="dkv">
                <tr><td>Vessel</td><td>{{ $cruise['property'] ?? '' }}</td></tr>
                <tr><td>Departure</td><td>{{ $cruise['embark'] ?? '' }} — {{ $cruise['departure_date'] ?? '' }}</td></tr>
                <tr><td>Duration</td><td>{{ $cruise['nights'] ?? '' }} Nights / {{ $cruise['days'] ?? '' }} Days</td></tr>
            </table>
        </td>
        <td>
            <table class="dkv">
                <tr><td>Itinerary</td><td>{{ $cruise['itinerary'] ?? '' }}</td></tr>
                <tr><td>Return</td><td>{{ $cruise['disembark'] ?? '' }} — {{ $cruise['return_date'] ?? '' }}</td></tr>
                <tr><td>Guests</td><td>{{ implode(' · ', $cruise['guests'] ?? []) }}</td></tr>
            </table>
        </td>
    </tr>
</table>
