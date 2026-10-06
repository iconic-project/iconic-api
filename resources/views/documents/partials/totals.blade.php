@php $totals = $snapshot['totals'] ?? []; @endphp
<div class="dtot">
    <table class="dkv">
        <tr><td>Stay charges</td><td>{{ \App\Support\Money::formatDocument((int) ($totals['vessel'] ?? 0)) }}</td></tr>
        <tr><td>Taxes and fees collected</td><td>{{ \App\Support\Money::formatDocument((int) ($totals['fees_collected'] ?? 0)) }}</td></tr>
        <tr><td>Other services</td><td>{{ \App\Support\Money::formatDocument((int) ($totals['extras'] ?? 0)) }}</td></tr>
    </table>
    <table class="dgrand">
        <tr>
            <td>INVOICE TOTAL</td>
            <td>USD {{ \App\Support\Money::formatDocument((int) ($totals['charges_total'] ?? 0)) }}</td>
        </tr>
    </table>
</div>
