@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    <p style="margin:18px 0">{{ $snapshot['description'] ?? '' }}</p>
    <div class="dsec">Day by day</div>
    @foreach($snapshot['day_plan'] ?? [] as $day)
        <table class="dkv">
            <tr><td>{{ $day[0] ?? '' }}</td><td>{{ $day[1] ?? '' }}</td></tr>
        </table>
    @endforeach
    <div class="dsec">Included</div>
    @foreach($snapshot['included'] ?? [] as $item)
        <div class="dnote">· {{ $item }}</div>
    @endforeach
    <div class="dsec">Not included</div>
    @foreach($snapshot['excluded'] ?? [] as $item)
        <div class="dnote">· {{ $item }}</div>
    @endforeach
    <div class="dsec">Before you travel</div>
    <div class="dnote">{{ $snapshot['before_you_travel'] ?? '' }}</div>
    <div class="dfoot">ICONIC · {{ $snapshot['reference'] ?? '' }}</div>
@endsection
