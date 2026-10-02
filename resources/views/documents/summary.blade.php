@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    @php
        $cruise = $snapshot['cruise'] ?? [];
        $totals = $snapshot['totals'] ?? [];
        $footer = $snapshot['footer'] ?? [];
    @endphp
    <p style="margin:18px 0">Dear {{ $snapshot['lead_name'] ?? '' }},</p>
    <p>We are delighted to confirm your expedition aboard {{ $cruise['yacht'] ?? '' }}. Everything is in place for an unforgettable journey through the Galápagos.</p>
    <div class="dsec">Your expedition</div>
    <table class="dkv">
        <tr><td>Vessel</td><td>{{ $cruise['yacht'] ?? '' }} — Intimate Yacht Expedition</td></tr>
        <tr><td>Itinerary</td><td>{{ $cruise['itinerary'] ?? '' }}</td></tr>
        <tr><td>Embarkation</td><td>{{ $cruise['embark'] ?? '' }} · {{ $cruise['departure_date'] ?? '' }}</td></tr>
        <tr><td>Disembarkation</td><td>{{ $cruise['disembark'] ?? '' }} · {{ $cruise['return_date'] ?? '' }}</td></tr>
        <tr><td>Duration</td><td>{{ $cruise['nights'] ?? '' }} Nights / {{ $cruise['days'] ?? '' }} Days</td></tr>
        <tr><td>Guests</td><td>{{ implode(' · ', $cruise['guests'] ?? []) }}</td></tr>
        <tr>
            <td>Accommodation</td>
            <td>
                @if(! empty($cruise['charter']))
                    Full yacht — private charter
                @else
                    {{ $cruise['cabin_label'] ?? '' }} — {{ $cruise['occupancy'] ?? '' }}
                @endif
            </td>
        </tr>
    </table>
    <div class="dsec">Investment summary</div>
    <table class="dkv">
        <tr>
            <td>
                @if(! empty($cruise['charter']))
                    Private charter — {{ $cruise['guest_count'] ?? 0 }} guests, {{ $cruise['nights'] ?? '' }} nights
                @else
                    {{ $cruise['cabin_label'] ?? '' }} — {{ $cruise['guest_count'] ?? 0 }} guest{{ (($cruise['guest_count'] ?? 0) === 1) ? '' : 's' }}, {{ $cruise['nights'] ?? '' }} nights
                @endif
            </td>
            <td>{{ \App\Support\Money::formatDocument((int) ($totals['vessel'] ?? 0)) }}</td>
        </tr>
        @if(((int) ($totals['extras'] ?? 0)) > 0)
            <tr><td>Ancillary services</td><td>{{ \App\Support\Money::formatDocument((int) $totals['extras']) }}</td></tr>
        @endif
        @if(((int) ($totals['fees_collected'] ?? 0)) > 0)
            <tr><td>Galápagos entry &amp; transit fees (collected by Iconic)</td><td>{{ \App\Support\Money::formatDocument((int) $totals['fees_collected']) }}</td></tr>
        @endif
    </table>
    <table class="dgrand">
        <tr><td>TOTAL</td><td>USD {{ \App\Support\Money::formatDocument((int) ($totals['charges_total'] ?? 0)) }}</td></tr>
    </table>
    @if(((int) ($totals['information_total'] ?? 0)) > 0)
        <div class="dnote" style="margin-top:6px">Paid directly by you on arrival / at the origin airport: USD {{ \App\Support\Money::formatDocument((int) $totals['information_total']) }} (Galápagos fees — not included above).</div>
    @endif
    <div class="dsec">Payment status</div>
    <table class="dkv">
        <tr><td>Paid to date</td><td>USD {{ \App\Support\Money::formatDocument((int) ($totals['paid'] ?? 0)) }} @if(! empty($snapshot['deposit_received'])) ✓ @endif</td></tr>
        <tr><td>Balance remaining</td><td>USD {{ \App\Support\Money::formatDocument((int) ($totals['balance'] ?? 0)) }}</td></tr>
        <tr><td>Balance due date</td><td>{{ $snapshot['balance_due_date'] ?? '' }} ({{ $snapshot['schedule']['balance_days'] ?? '' }} days before departure)</td></tr>
    </table>
    <p class="dnote" style="margin-top:16px">For payment instructions or any questions regarding your reservation, please contact us at {{ $footer['email'] ?? '' }} — we are at your service.</p>
    @include('documents.partials.footer')
@endsection
