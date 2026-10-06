@extends('documents.layout')

@section('body')
    @include('documents.partials.head')
    @include('documents.partials.stay')
    @if(! empty($snapshot['description']))
        <p style="margin:18px 0">{{ $snapshot['description'] }}</p>
    @endif
    @foreach($snapshot['day_plan'] ?? [] as $day)
        <table class="dkv">
            <tr><td>{{ $day[0] ?? '' }}</td><td>{{ $day[1] ?? '' }}</td></tr>
        </table>
    @endforeach
    @if(! empty($snapshot['policies']))
        <div class="dsec">House policies</div>
        <div class="dnote">{{ $snapshot['policies'] }}</div>
    @endif
    <div class="dsec">Before you arrive</div>
    <div class="dnote">{{ $snapshot['before_you_arrive'] ?? '' }}</div>
    <div class="dfoot">ICONIC · {{ $snapshot['reference'] ?? '' }}</div>
@endsection
