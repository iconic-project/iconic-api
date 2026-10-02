<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Charter proposal {{ $snapshot['number'] ?? '' }}</title></head>
<body>
<h1>Charter proposal {{ $snapshot['number'] ?? '' }} · v{{ $snapshot['version'] ?? '' }}</h1>
<p>{{ $snapshot['property'] ?? '' }} · {{ $snapshot['departure'] ?? '' }} to {{ $snapshot['return'] ?? '' }} · {{ $snapshot['guests'] ?? '' }} guests</p>
<h2>Price</h2>
<ul>
@foreach (($snapshot['lines'] ?? []) as $line)
<li>{{ $line['label'] ?? '' }} — {{ $line['amount'] ?? '' }}</li>
@endforeach
</ul>
<p>Total {{ $snapshot['total'] ?? '' }}. Deposit {{ $snapshot['deposit_pct'] ?? '' }}% ({{ $snapshot['deposit'] ?? '' }}) due within {{ $snapshot['deposit_business_days'] ?? '' }} business days of acceptance. Balance at T−{{ $snapshot['balance_days'] ?? '' }}.</p>
<h2>Included</h2>
<ul>
@foreach (($snapshot['included'] ?? []) as $item)
<li>{{ $item }}</li>
@endforeach
</ul>
<p>{{ $snapshot['excluded'] ?? '' }}</p>
<h2>Cancellation</h2>
<ul>
@foreach (($snapshot['bands'] ?? []) as $band)
<li>{{ $band }}</li>
@endforeach
</ul>
<p>Valid until {{ $snapshot['valid_until'] ?? '' }}.</p>
</body>
</html>
