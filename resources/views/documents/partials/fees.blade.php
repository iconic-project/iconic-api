@php
    $fees = $snapshot['fees'] ?? ['collected' => [], 'information' => []];
    $totals = $snapshot['totals'] ?? [];
@endphp
<div class="dsec">Galápagos fees &amp; transit card</div>
@if(($fees['collected'] ?? []) === [])
    <div class="dnote">No Galápagos fees collected by Iconic for this booking.</div>
@else
    @include('documents.partials.lines', [
        'rows' => $fees['collected'],
        'headers' => ['Fee collected by Iconic', '# PAX', 'Rate (USD)', 'Amount (USD)'],
    ])
@endif
<table class="dsubt"><tr><td>FEES SUBTOTAL (COLLECTED)</td><td>{{ \App\Support\Money::formatDocument((int) ($totals['fees_collected'] ?? 0)) }}</td></tr></table>
@if(($fees['information'] ?? []) !== [])
    <div class="dnote"><b>Paid directly by the guest — information only, not included in this invoice:</b></div>
    @include('documents.partials.lines', [
        'rows' => $fees['information'],
        'headers' => ['Fee paid by the guest', '# PAX', 'Rate (USD)', 'Amount (USD)'],
    ])
    <div class="dnote">PNG entry fee is paid at SCY airport on arrival; TCT may be pre-registered online or paid at the origin airport. Total {{ \App\Support\Money::formatDocument((int) ($totals['information_total'] ?? 0)) }}.</div>
@endif
