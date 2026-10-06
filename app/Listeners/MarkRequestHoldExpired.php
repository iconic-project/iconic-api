<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\HoldExpired;
use App\Models\Booking;
use App\Support\History\History;

final class MarkRequestHoldExpired
{
    public function handle(HoldExpired $event): void
    {
        $holder = $event->holder;

        if (! $holder instanceof Booking || $holder->status !== BookingStatus::Requested) {
            return;
        }

        $holder->loadMissing('bookingRequest');
        $request = $holder->bookingRequest;

        if ($request === null || $request->hold_expired_at !== null) {
            return;
        }

        $request->hold_expired_at = now();
        $request->save();

        History::record($holder, 'request.hold_expired', after: [
            'what' => 'Hold expired (TEC-004) — room returned to inventory; the request stays open for review',
        ], system: true);
        // TODO(Sprint 7): notify the owner
    }
}
