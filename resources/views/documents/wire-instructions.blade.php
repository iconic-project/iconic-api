@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    @if(! empty($snapshot['placeholders']))
        <p class="dnote warn">These bank details are placeholders and must not be used. PONTOS LLC wire instructions are pending (LEG-004).</p>
    @endif
    <table class="dkv">
        <tr><td>{{ $snapshot['amount_label'] ?? 'Amount due' }}</td><td>USD {{ \App\Support\Money::formatDocument((int) ($snapshot['amount'] ?? 0)) }}</td></tr>
        <tr><td>Wire window</td><td>{{ $snapshot['wire_window_hours'] ?? '' }} hours</td></tr>
    </table>
    @include('documents.partials.wire')
    <div class="dfoot">ICONIC · {{ $snapshot['footer']['email'] ?? '' }} · {{ $snapshot['footer']['website'] ?? '' }}</div>
@endsection
