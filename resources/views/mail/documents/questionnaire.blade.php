@extends('mail.documents.layout')

@section('body')
    <p>Your preferences questionnaire arrives with your pre-arrival information for the stay checking in {{ $booking->stay()->checkIn()->toDateString() }} ({{ $booking->reference }}).</p>
    <p style="margin: 18px 0;">
        <a href="{{ $questionnaireUrl }}" style="display: inline-block; padding: 10px 18px; background: #2c4a3a; color: #f4efe4; text-decoration: none; letter-spacing: 0.08em; font-size: 13px;">Open the questionnaire</a>
    </p>
    <p>Questions can go to {{ $replyTo }}.</p>
@endsection
