@php
    $schedule = $snapshot['schedule'] ?? [];
    $totals = $snapshot['totals'] ?? [];
    $hours = (int) ($schedule['extras_due_hours'] ?? 0);
@endphp
<div class="dsec">Payment schedule</div>
<div class="dnote">The deposit is calculated on the stay charges. Other services and any fees collected are due up to {{ $hours }} h before check-in. Anything added during the stay is settled during the stay.</div>
<table class="dkv">
    <tr>
        <td>Deposit ({{ $schedule['deposit_pct'] ?? 0 }}% of stay charges)</td>
        <td>USD {{ \App\Support\Money::formatDocument((int) ($totals['deposit_amount'] ?? 0)) }} — due at booking confirmation @if(! empty($schedule['deposit_received'])) ✓ RECEIVED @endif</td>
    </tr>
    <tr>
        <td>Stay balance ({{ $schedule['balance_pct'] ?? 0 }}%)</td>
        <td>USD {{ \App\Support\Money::formatDocument((int) ($totals['cruise_balance_amount'] ?? 0)) }} — due {{ $schedule['balance_days'] ?? '' }} days before check-in: {{ $snapshot['balance_due_date'] ?? '' }}@if(! empty($schedule['cruise_received'])) ✓ RECEIVED @endif</td>
    </tr>
    @if(((int) ($totals['extras_and_fees'] ?? 0)) > 0)
        <tr>
            <td>Other services and fees collected</td>
            <td>
                USD {{ \App\Support\Money::formatDocument((int) $totals['extras_and_fees']) }} — due up to {{ $hours }} h before check-in: {{ $schedule['extras_due_date'] ?? '' }}
                @if(! empty($schedule['on_board_note']))
                    <br>Services taken during the stay are settled during the stay
                @endif
            </td>
        </tr>
    @endif
    <tr><td>Cancellation</td><td>{{ $schedule['cancellation'] ?? '' }}</td></tr>
    <tr><td>Insurance</td><td>{{ $snapshot['insurance'] ?? '' }}</td></tr>
</table>
