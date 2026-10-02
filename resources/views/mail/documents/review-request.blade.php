@extends('mail.documents.layout')

@section('body')
    <p>Thank you for sailing with Iconic ({{ $booking->reference }}). If you have a moment, a public review helps other travellers.</p>
    <p style="margin: 18px 0;">
        <a href="{{ $reviewUrl }}" style="display: inline-block; padding: 10px 18px; background: #2c4a3a; color: #f4efe4; text-decoration: none; letter-spacing: 0.08em; font-size: 13px;">Write a review</a>
    </p>
    <p>Questions can go to {{ $replyTo }}.</p>
@endsection
