<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Crm\RaiseTask;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Models\Booking;
use App\Support\Alerts\AlertKeys;
use App\Support\BusinessTime;
use App\Support\Crm\TaskSweep;
use Illuminate\Database\Eloquent\Builder;

/**
 * Raises front-desk alerts and tasks. Never writes bookings.status (09 H11).
 */
final class NightAudit
{
    public function __construct(
        private readonly RaiseAlert $alerts,
        private readonly RaiseTask $tasks,
    ) {}

    public function run(): void
    {
        $today = BusinessTime::now()->toDateString();

        $this->each($this->arrivals($today), AlertKind::ArrivalNotCheckedIn, 'Arrival not checked in', AlertKeys::arrivalNotCheckedIn(...));
        $this->each($this->pastCheckOut($today), AlertKind::InHousePastCheckOut, 'In house past check-out', AlertKeys::inHousePastCheckOut(...));
        $this->each($this->departuresToday($today), AlertKind::DepartureNotCheckedOut, 'Check-out today not completed', AlertKeys::checkOutStillOpen(...));
    }

    /**
     * @param  Builder<Booking>  $query
     * @param  callable(int): string  $key
     */
    private function each(Builder $query, AlertKind $kind, string $title, callable $key): void
    {
        $query->orderBy('id')->each(function (Booking $booking) use ($kind, $title, $key): void {
            $reference = TaskSweep::reference($booking);
            $base = $key($booking->id);
            $sentence = $reference.' · '.$title.'.';

            $this->alerts->handle($kind, $base, $title.' '.$reference, $sentence, bookingId: $booking->id);
            $this->tasks->handle(
                TaskKind::FrontDesk,
                $base,
                $title.' · '.$reference,
                $sentence,
                BusinessTime::now(),
                $booking->owner_id,
                Permission::BookingsFrontDesk,
                contactId: $booking->contact_id,
                bookingId: $booking->id,
            );
        });
    }

    /**
     * @return Builder<Booking>
     */
    private function arrivals(string $today): Builder
    {
        return Booking::query()
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::FullyPaid])
            ->whereDate('check_in', '<=', $today)
            ->whereNull('checked_in_at');
    }

    /**
     * @return Builder<Booking>
     */
    private function pastCheckOut(string $today): Builder
    {
        return Booking::query()
            ->where('status', BookingStatus::InHouse)
            ->whereDate('check_out', '<', $today);
    }

    /**
     * @return Builder<Booking>
     */
    private function departuresToday(string $today): Builder
    {
        return Booking::query()
            ->where('status', BookingStatus::InHouse)
            ->whereDate('check_out', $today);
    }
}
