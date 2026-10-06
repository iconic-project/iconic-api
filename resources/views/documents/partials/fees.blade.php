@php
    $taxes = $snapshot['stay']['taxes'] ?? ['charged' => [], 'information' => []];
    $fees = $snapshot['fees'] ?? ['collected' => [], 'information' => []];
    $totals = $snapshot['totals'] ?? [];
    $charged = array_merge($taxes['charged'] ?? [], $fees['collected'] ?? []);
    $information = array_merge($taxes['information'] ?? [], $fees['information'] ?? []);
@endphp
<div class="dsec">Taxes and fees</div>
@if($charged === [])
    <div class="dnote">No taxes or fees collected on this stay.</div>
@else
    @include('documents.partials.lines', [
        'rows' => $charged,
        'headers' => ['Collected', 'Qty', 'Rate (USD)', 'Amount (USD)'],
    ])
@endif
<table class="dsubt"><tr><td>FEES SUBTOTAL (COLLECTED)</td><td>{{ \App\Support\Money::formatDocument((int) ($totals['fees_collected'] ?? 0)) }}</td></tr></table>
@if($information !== [])
    <div class="dnote"><b>Paid directly by the guest — information only, not included in this invoice:</b></div>
    @include('documents.partials.lines', [
        'rows' => $information,
        'headers' => ['Paid by the guest', 'Qty', 'Rate (USD)', 'Amount (USD)'],
    ])
    <div class="dnote">Total {{ \App\Support\Money::formatDocument((int) ($totals['information_total'] ?? 0)) }}.</div>
@endif
