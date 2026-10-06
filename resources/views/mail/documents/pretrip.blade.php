@extends('mail.documents.layout')

@section('body')
    <p>Please find attached the pre-arrival information for reservation <b>{{ $booking->displayReference() }}</b>.</p>
    <p>Read it before you travel. Questions? Reply to this email.</p>
@endsection
