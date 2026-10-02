@extends('mail.documents.layout')

@section('body')
    <p>Please find attached your transfer voucher for reservation <b>{{ $booking->displayReference() }}</b>.</p>
    <p>Present this voucher to the Iconic ground team on arrival.</p>
@endsection
