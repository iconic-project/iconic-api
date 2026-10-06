@extends('mail.documents.layout')

@section('body')
    <p>Your stay checking in on {{ $booking->stay()->checkIn()->toDateString() }} is approaching. As a gentle reminder, the stay balance of <b>{{ $balance }}</b> is due on <b>{{ $dueDate }}</b> — {{ $days }} days from today's reminder.</p>
    @if ($payUrl)
        <p style="margin: 18px 0;">
            <a href="{{ $payUrl }}" style="display: inline-block; padding: 10px 18px; background: #2c4a3a; color: #f4efe4; text-decoration: none; letter-spacing: 0.08em; font-size: 13px;">Pay balance securely</a>
        </p>
    @else
        <p>To pay the balance, contact the team at {{ $replyTo }}.</p>
    @endif
    <p>If you have already arranged payment, thank you — please disregard this message.</p>
@endsection
