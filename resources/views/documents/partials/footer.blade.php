@php $footer = $snapshot['footer'] ?? []; @endphp
<div class="dfoot">
    Thank you for choosing Iconic. We look forward to welcoming you.<br>
    ICONIC · {{ $footer['email'] ?? '' }} · {{ $footer['website'] ?? '' }}
</div>
