@extends('mail.documents.layout')

@section('body')
    <p>Please find attached your booking summary for reservation <b>{{ $booking->displayReference() }}</b>.</p>
    <p>We look forward to welcoming you.</p>
@endsection
