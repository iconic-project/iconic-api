<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class SendOnBookingStatusChanged implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(BookingStatusChanged $event): void
    {
        // TODO(OPEN: 19-03) hotel documents are not issued on these transitions.
        unset($event);
    }
}
