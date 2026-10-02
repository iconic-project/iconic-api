@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    <table class="dkv">
        <tr><td>Guests</td><td>{{ implode(' · ', $snapshot['guests'] ?? []) }}</td></tr>
        <tr><td>Arrival</td><td>San Cristóbal (SCY) airport — {{ $snapshot['arrival'] ?? '' }}</td></tr>
        <tr><td>Transfer</td><td>{{ $snapshot['transfer'] ?? '' }}</td></tr>
        <tr><td>Services</td><td>{{ implode(' · ', $snapshot['services'] ?? []) }}</td></tr>
    </table>
    <div class="dnote" style="margin-top:12px">Present this voucher to the Iconic ground team on arrival.</div>
    <div class="dfoot">ICONIC · {{ $snapshot['footer']['email'] ?? '' }}</div>
@endsection
