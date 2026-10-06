@php
    $doc = $snapshot['document'] ?? [];
    $number = $doc['number'] ?? null;
    $version = $doc['version'] ?? null;
    $sub = [];
    if (! empty($snapshot['subtitle'])) {
        $sub[] = $snapshot['subtitle'];
    }
    if (! empty($snapshot['reference']) && empty($snapshot['subtitle'])) {
        $sub[] = 'Ref: '.$snapshot['reference'];
    }
    if ($number) {
        $sub[] = $number.($version ? ' · v'.$version : '');
    }
    if (! empty($snapshot['date'])) {
        $sub[] = 'Date: '.$snapshot['date'];
    }
@endphp
<table class="dh">
    <tr>
        <td>
            <div class="dlogo">ICONIC</div>
            <div class="dtag">Reservations</div>
        </td>
        <td>
            <div class="dtitle">{{ $doc['title'] ?? '' }}</div>
            <div class="dsub">
                {{ implode(' · ', $sub) }}
                @if(! empty($snapshot['balance_due_date']))
                    <br>Balance due: {{ $snapshot['balance_due_date'] }}
                @endif
                @if(! empty($snapshot['status_line']))
                    <br><b>● {{ $snapshot['status_line'] }}</b>
                @endif
            </div>
        </td>
    </tr>
</table>
