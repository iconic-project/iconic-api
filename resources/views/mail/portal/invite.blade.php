<!DOCTYPE html>
<html lang="en">
<body>
<p>{{ $agencyUser->agency->name }}, you have been invited to the Iconic agent portal.</p>
<p><a href="{{ $acceptUrl }}">Set your password</a></p>
<p>This invitation expires on {{ $agencyUser->invite_expires_at?->toFormattedDateString() }}.</p>
</body>
</html>
