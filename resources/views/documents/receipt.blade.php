@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    <p style="margin:18px 0">We confirm receipt of your payment for reservation <b>{{ $snapshot['booking_reference'] ?? '' }}</b>.</p>
    <table class="dkv">
        <tr><td>Date</td><td>{{ $snapshot['date'] ?? '' }}</td></tr>
        <tr><td>Payment</td><td>{{ $snapshot['kind'] ?? '' }}</td></tr>
        <tr><td>Method</td><td>{{ $snapshot['method'] ?? '' }}</td></tr>
        <tr><td>Amount</td><td>USD {{ \App\Support\Money::formatDocument((int) ($snapshot['amount'] ?? 0)) }}</td></tr>
        <tr><td>Reference</td><td>{{ $snapshot['reference'] ?? '' }}</td></tr>
    </table>
    <table class="dgrand">
        <tr><td>UPDATED BALANCE</td><td>USD {{ \App\Support\Money::formatDocument((int) ($snapshot['balance_after'] ?? 0)) }}</td></tr>
    </table>
    <div class="dfoot">ICONIC · {{ $snapshot['footer']['email'] ?? '' }} · {{ $snapshot['footer']['website'] ?? '' }}</div>
@endsection
