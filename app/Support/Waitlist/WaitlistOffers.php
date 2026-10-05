<?php

declare(strict_types=1);

namespace App\Support\Waitlist;

use App\Actions\Crm\CloseTask;
use App\Actions\Waitlist\OfferWaitlistEntry;
use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\DeliveryKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Departure;
use App\Models\WaitlistEntry;
use App\Services\Inventory\Availability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class WaitlistOffers
{
    public function __construct(
        private readonly Availability $availability,
        private readonly OfferWaitlistEntry $offer,
        private readonly CloseTask $close,
    ) {}

    /**
     * @param  list<int>  $departureIds
     */
    public function forDepartures(array $departureIds): int
    {
        $ids = array_values(array_unique(array_filter($departureIds, fn (int $id): bool => $id > 0)));
        $sent = $ids === [] ? 0 : $this->offerWhere($ids);
        $this->closeSettled();

        return $sent;
    }

    public function sweep(): int
    {
        $sent = $this->offerWhere(null);
        $this->closeSettled();

        return $sent;
    }

    /**
     * Free cabins come from Availability. A notice already sent still occupies a
     * slot, so a second free cabin notifies the next person and a round with no
     * new free cabin sends nothing. Nothing is claimed.
     *
     * @param  list<int>|null  $departureIds
     */
    private function offerWhere(?array $departureIds): int
    {
        $waiting = WaitlistEntry::query()
            ->active()
            ->whereNull('notified_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('deliveries')
                    ->where('kind', DeliveryKind::WaitlistOffer->value)
                    ->whereRaw("deliveries.idempotency_key = CONCAT('waitlist:', waitlist_entries.id)");
            })
            ->when(
                $departureIds !== null,
                fn (Builder $query) => $query->whereIn('departure_id', $departureIds),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($waiting->isEmpty()) {
            return 0;
        }

        $departures = Departure::query()
            ->whereIn('id', $waiting->pluck('departure_id')->unique()->all())
            ->get();
        $snapshots = $this->availability->forDepartures($departures);

        $sent = 0;

        foreach ($waiting->groupBy(fn (WaitlistEntry $entry): string => $entry->departure_id.'|'.$entry->cabin_category->value) as $group) {
            /** @var Collection<int, WaitlistEntry> $group */
            $first = $group->first();

            if (! $first instanceof WaitlistEntry) {
                continue;
            }

            $snapshot = $snapshots[$first->departure_id] ?? null;

            if ($snapshot === null) {
                continue;
            }

            $free = $first->cabin_category === CabinCategory::Owner
                ? ($snapshot->counts['owner_free'] ? 1 : 0)
                : $snapshot->counts['suites_free'];
            $notified = WaitlistEntry::query()
                ->active()
                ->where('departure_id', $first->departure_id)
                ->where('cabin_category', $first->cabin_category)
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

                if ($this->offer->handle($entry)) {
                    $sent++;
                    $slots--;
                }
            }
        }

        return $sent;
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
                    ->where('departure_id', $entry->departure_id)
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
                    $this->close->autoClose($task, 'the contact booked the departure');
                }
            });
    }
}
