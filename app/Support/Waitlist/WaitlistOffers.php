<?php

declare(strict_types=1);

namespace App\Support\Waitlist;

use App\Actions\Crm\CloseTask;
use App\Actions\Waitlist\OfferWaitlistEntry;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\WaitlistEntry;
use App\Services\Inventory\NightAvailability;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class WaitlistOffers
{
    public function __construct(
        private readonly NightAvailability $availability,
        private readonly OfferWaitlistEntry $offer,
        private readonly CloseTask $close,
    ) {}

    public function sweep(): int
    {
        $sent = $this->offerWhere(WaitlistEntry::query());
        $this->closeSettled();

        return $sent;
    }

    public function forOverlap(int $propertyId, StayDates $window): int
    {
        $sent = $this->offerWhere(
            WaitlistEntry::query()
                ->whereHas('roomType', fn (Builder $query) => $query->where('property_id', $propertyId))
                ->whereDate('check_in', '<', $window->checkOut()->toDateString())
                ->whereDate('check_out', '>', $window->checkIn()->toDateString()),
        );
        $this->closeSettled();

        return $sent;
    }

    public function forClaim(RoomNightClaim $claim): int
    {
        if ($claim->getAttribute('room_id') === null || $claim->getAttribute('night') === null) {
            return 0;
        }

        $claim->loadMissing('room');

        return $this->forOverlap(
            (int) $claim->room->property_id,
            StayDates::forNights($claim->night, 1),
        );
    }

    /**
     * A notice already sent still occupies a room, so a second free room
     * notifies the next person. Nothing is claimed. A stay that is only
     * partly free sends nothing.
     *
     * @param  Builder<WaitlistEntry>  $query
     */
    private function offerWhere(Builder $query): int
    {
        $waiting = $query
            ->active()
            ->whereNull('notified_at')
            ->whereNotNull('room_type_id')
            ->whereNotExists(function ($inner): void {
                $inner->selectRaw('1')
                    ->from('deliveries')
                    ->where('kind', DeliveryKind::WaitlistOffer->value)
                    ->whereRaw("deliveries.idempotency_key = CONCAT('waitlist:', waitlist_entries.id)");
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($waiting->isEmpty()) {
            return 0;
        }

        $types = RoomType::query()
            ->whereIn('id', $waiting->pluck('room_type_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $sent = 0;

        foreach ($waiting->groupBy(fn (WaitlistEntry $entry): string => $entry->room_type_id.'|'.$entry->check_in->toDateString().'|'.$entry->check_out->toDateString()) as $group) {
            /** @var Collection<int, WaitlistEntry> $group */
            $first = $group->first();
            $type = $first instanceof WaitlistEntry ? $types->get($first->room_type_id) : null;

            if (! $first instanceof WaitlistEntry || ! $type instanceof RoomType) {
                continue;
            }

            $stay = StayDates::of($first->check_in, $first->check_out);
            $free = $this->freeRooms($type, $stay);
            $notified = WaitlistEntry::query()
                ->active()
                ->where('room_type_id', $first->room_type_id)
                ->whereDate('check_in', $first->check_in->toDateString())
                ->whereDate('check_out', $first->check_out->toDateString())
                ->whereNotNull('notified_at')
                ->count();
            $slots = max(0, $free - $notified);

            if ($slots === 0) {
                continue;
            }

            foreach ($group as $entry) {
                if ($slots === 0) {
                    break;
                }

                if (! $this->partyFits($type, $entry)) {
                    continue;
                }

                if ($this->offer->handle($entry)) {
                    $sent++;
                    $slots--;
                }
            }
        }

        return $sent;
    }

    private function freeRooms(RoomType $type, StayDates $stay): int
    {
        $one = $this->availability->canBook($type, $stay, 1);

        if (! $one->ok) {
            return 0;
        }

        $free = $one->nights === [] ? 0 : min(array_map(fn (array $night): int => $night['free'], $one->nights));

        while ($free > 1 && ! $this->availability->canBook($type, $stay, $free)->ok) {
            $free--;
        }

        return $free;
    }

    private function partyFits(RoomType $type, WaitlistEntry $entry): bool
    {
        return $entry->adults <= $type->max_adults
            && $entry->children <= $type->max_children
            && ($entry->adults + $entry->children) <= $type->max_occupancy;
    }

    private function closeSettled(): void
    {
        CrmTask::query()
            ->where('kind', TaskKind::WaitlistFollowUp)
            ->where('status', TaskStatus::Open)
            ->each(function (CrmTask $task): void {
                $entryId = substr($task->idempotency_key, strlen('waitlist-follow-up:'));

                if ($entryId === '' || ! ctype_digit($entryId)) {
                    return;
                }

                $entry = WaitlistEntry::query()->find((int) $entryId);

                if (! $entry instanceof WaitlistEntry) {
                    return;
                }

                if ($entry->removed_at !== null) {
                    $this->close->autoClose($task, 'the waitlist entry was removed');

                    return;
                }

                $booked = Booking::query()
                    ->where('contact_id', $entry->contact_id)
                    ->where('room_type_id', $entry->room_type_id)
                    ->whereDate('check_in', $entry->check_in->toDateString())
                    ->whereDate('check_out', $entry->check_out->toDateString())
                    ->whereIn('status', [
                        BookingStatus::PendingPayment,
                        BookingStatus::Confirmed,
                        BookingStatus::FullyPaid,
                        BookingStatus::InHouse,
                        BookingStatus::CheckedOut,
                        BookingStatus::Overdue,
                        BookingStatus::OnHoldAgency,
                    ])
                    ->exists();

                if ($booked) {
                    $this->close->autoClose($task, 'the contact booked the stay');
                }
            });
    }
}
