<?php

declare(strict_types=1);

namespace App\Actions\Extras;

use App\Actions\Action;
use App\Events\BookingChargesChanged;
use App\Models\BookingExtra;
use App\Models\User;
use App\Support\Bookings\BookingCharges;
use App\Support\Bookings\BookingMutationLock;
use App\Support\History\History;

final class RemoveBookingExtra extends Action
{
    public function handle(BookingExtra $extra, User $actor): void
    {
        $this->transaction(function () use ($extra, $actor): void {
            $extra->load('booking');
            $booking = BookingMutationLock::acquire($extra->booking);
            BookingCharges::assertWritable($booking);

            $extra = BookingExtra::query()->whereKey($extra->getKey())->lockForUpdate()->firstOrFail();
            $extra->delete();

            $reason = 'Extra removed — '.$extra->name.' × '.$extra->qty;

            History::record($booking, 'extra.removed', before: [
                'extra_id' => $extra->id,
                'code' => $extra->code,
                'name' => $extra->name,
                'qty' => $extra->qty,
                'rate_usd' => $extra->rate_usd,
                'note' => $extra->note,
            ], after: [
                'what' => $reason,
            ], actor: $actor);

            BookingChargesChanged::dispatch($booking, $reason);
        });
    }
}
