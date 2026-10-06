@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    <table class="dg2">
        <tr>
            <td>@include('documents.partials.issued-by')</td>
            <td>@include('documents.partials.guest-billing')</td>
        </tr>
    </table>
    @include('documents.partials.stay')
    <div class="dsec">Stay charges</div>
    @include('documents.partials.lines', [
        'rows' => $snapshot['vessel_rows'] ?? [],
        'headers' => ['Concept', 'Nights', 'Rate (USD)', 'Amount (USD)'],
    ])
    <table class="dsubt"><tr><td>STAY SUBTOTAL</td><td>{{ \App\Support\Money::formatDocument((int) ($snapshot['totals']['vessel'] ?? 0)) }}</td></tr></table>
    @include('documents.partials.fees')
    @include('documents.partials.ancillary')
    @include('documents.partials.totals')
    @include('documents.partials.schedule')
    @include('documents.partials.history')
    @include('documents.partials.wire')
    @include('documents.partials.footer')
@endsection
