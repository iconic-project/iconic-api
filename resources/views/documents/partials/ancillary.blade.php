@php
    $rows = $snapshot['extras_rows'] ?? [];
    $totals = $snapshot['totals'] ?? [];
@endphp
<div class="dsec">Other services</div>
@if($rows === [])
    <div class="dnote">No additional services contracted.</div>
@else
    @include('documents.partials.lines', [
        'rows' => $rows,
        'headers' => ['Service', 'Qty', 'Rate (USD)', 'Amount (USD)'],
    ])
@endif
<table class="dsubt"><tr><td>OTHER SERVICES SUBTOTAL</td><td>{{ \App\Support\Money::formatDocument((int) ($totals['extras'] ?? 0)) }}</td></tr></table>
