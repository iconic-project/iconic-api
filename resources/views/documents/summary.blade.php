@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    @php
        $stay = $snapshot['stay'] ?? [];
        $party = $stay['party'] ?? [];
        $totals = $snapshot['totals'] ?? [];
        $footer = $snapshot['footer'] ?? [];
        $room = $stay['room'] ?? null;
    @endphp
    <p style="margin:18px 0">Dear {{ $snapshot['lead_name'] ?? '' }},</p>
    <p>We are delighted to confirm your stay at {{ $stay['property'] ?? '' }}.</p>
    @include('documents.partials.stay')
    <div class="dsec">Investment summary</div>
    <table class="dkv">
        <tr>
            <td>
                @if(! empty($stay['entire_property']))
                    Entire property — {{ count($party['guests'] ?? []) }} guests, {{ $stay['nights'] ?? '' }} nights
                @else
                    {{ $stay['room_type'] ?? '' }}@if(is_array($room)) · {{ $room['label'] ?? '' }}@endif — {{ $stay['nights'] ?? '' }} nights
                @endif
            </td>
            <td>{{ \App\Support\Money::formatDocument((int) ($totals['vessel'] ?? 0)) }}</td>
        </tr>
        @if(((int) ($totals['extras'] ?? 0)) > 0)
            <tr><td>Other services</td><td>{{ \App\Support\Money::formatDocument((int) $totals['extras']) }}</td></tr>
        @endif
        @if(((int) ($totals['fees_collected'] ?? 0)) > 0)
            <tr><td>Taxes and fees collected</td><td>{{ \App\Support\Money::formatDocument((int) $totals['fees_collected']) }}</td></tr>
        @endif
    </table>
    <table class="dgrand">
        <tr><td>TOTAL</td><td>USD {{ \App\Support\Money::formatDocument((int) ($totals['charges_total'] ?? 0)) }}</td></tr>
    </table>
    @if(((int) ($totals['information_total'] ?? 0)) > 0)
        <div class="dnote" style="margin-top:6px">Paid directly by you: USD {{ \App\Support\Money::formatDocument((int) $totals['information_total']) }} (not included above).</div>
    @endif
    <div class="dsec">Payment status</div>
    <table class="dkv">
        <tr><td>Paid to date</td><td>USD {{ \App\Support\Money::formatDocument((int) ($totals['paid'] ?? 0)) }} @if(! empty($snapshot['deposit_received'])) ✓ @endif</td></tr>
        <tr><td>Balance remaining</td><td>USD {{ \App\Support\Money::formatDocument((int) ($totals['balance'] ?? 0)) }}</td></tr>
        <tr><td>Balance due date</td><td>{{ $snapshot['balance_due_date'] ?? '' }} ({{ $snapshot['schedule']['balance_days'] ?? '' }} days before check-in)</td></tr>
    </table>
    <p class="dnote" style="margin-top:16px">For payment instructions or any questions regarding your reservation, please contact us at {{ $footer['email'] ?? '' }} — we are at your service.</p>
    @include('documents.partials.footer')
@endsection
