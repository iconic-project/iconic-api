@php
    $schedule = $snapshot['schedule'] ?? [];
    $totals = $snapshot['totals'] ?? [];
    $hours = (int) ($schedule['extras_due_hours'] ?? 0);
@endphp
<div class="dsec">Payment schedule</div>
<div class="dnote">The deposit is calculated on the cruise (cabin) charges only. Ancillary services and any fees Iconic collects are due up to {{ $hours }} h before departure; anything added on board is settled during or after the cruise.</div>
<table class="dkv">
    <tr>
        <td>Deposit ({{ $schedule['deposit_pct'] ?? 0 }}% of cruise charges)</td>
        <td>USD {{ \App\Support\Money::formatDocument((int) ($totals['deposit_amount'] ?? 0)) }} — due at booking confirmation @if(! empty($schedule['deposit_received'])) ✓ RECEIVED @endif</td>
    </tr>
    <tr>
        <td>Cruise balance ({{ $schedule['balance_pct'] ?? 0 }}%)</td>
        <td>USD {{ \App\Support\Money::formatDocument((int) ($totals['cruise_balance_amount'] ?? 0)) }} — due {{ $schedule['balance_days'] ?? '' }} days before departure: {{ $snapshot['balance_due_date'] ?? '' }}@if(! empty($schedule['cruise_received'])) ✓ RECEIVED @endif</td>
    </tr>
    @if(((int) ($totals['extras_and_fees'] ?? 0)) > 0)
        <tr>
            <td>Ancillary services &amp; fees collected by Iconic</td>
            <td>
                USD {{ \App\Support\Money::formatDocument((int) $totals['extras_and_fees']) }} — due up to {{ $hours }} h before departure: {{ $schedule['extras_due_date'] ?? '' }}
                @if(! empty($schedule['on_board_note']))
                    <br>Services taken on board are settled during or after the cruise
                @endif
            </td>
        </tr>
    @endif
    <tr><td>Cancellation</td><td>{{ $schedule['cancellation'] ?? '' }}</td></tr>
    <tr><td>Insurance</td><td>{{ $snapshot['insurance'] ?? '' }}</td></tr>
</table>
