<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\BookabilityReason;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\RoomNightState;
use App\Enums\RoomStatus;
use App\Exceptions\RoomUnavailableException;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * What is free on each night of a property.
 * canBook returns every restriction reason and the inventory reason, in the
 * order documented on StayRestrictionResource.
 */
final class NightAvailability
{
    public const int MAX_NIGHTS = 62;

    public function __construct(
        private readonly RoomAllocator $allocator,
        private readonly CurrentConfig $config,
        private readonly Restrictions $restrictions,
    ) {}

    public function grid(Property $property, CarbonInterface $from, CarbonInterface $to): NightGrid
    {
        $tally = $this->tally($property, $from, $to);

        return new NightGrid(
            [
                'id' => $property->id,
                'code' => $property->code,
                'name' => $property->name,
            ],
            $tally['from'],
            $tally['to'],
            $tally['nights'],
            $tally['rooms'],
            $tally['counts'],
            $tally['occupancy'],
            $tally['kpis'],
        );
    }

    /**
     * @return array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>
     */
    public function countsByType(Property $property, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->tally($property, $from, $to)['counts'];
    }

    /**
     * Blocked room-nights are not available. Sold, held and free room-nights are.
     * pct is sold × 100 ÷ available, integer division, or 0 when nothing is available.
     *
     * @return array{room_nights_available: int, room_nights_sold: int, pct: int}
     */
    public function occupancy(Property $property, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->tally($property, $from, $to)['occupancy'];
    }

    /**
     * @return array{occupancy_pct: int, free_room_nights: int, nights_fully_sold: int, nights_below_threshold: int}
     */
    public function kpis(Property $property, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->tally($property, $from, $to)['kpis'];
    }

    public function canBook(RoomType $type, StayDates $stay, int $rooms = 1): Bookability
    {
        if ($rooms < 1) {
            throw new InvalidArgumentException('A stay needs at least one room.');
        }

        $nights = $this->allocator->explain($type, $stay);
        $reasons = $this->restrictions->evaluate($type, $stay)->reasons;
        $short = false;

        foreach ($nights as $night) {
            if ($night['free'] < $rooms) {
                $short = true;
            }
        }

        if ($short) {
            $reasons[] = BookabilityReason::SoldOut->value;
        } else {
            try {
                $this->allocator->pick($type, $stay, $rooms);
            } catch (RoomUnavailableException) {
                $reasons[] = BookabilityReason::NoSingleRoom->value;
            }
        }

        $reasons = Bookability::ordered($reasons);

        return new Bookability($reasons === [], $reasons, $nights);
    }

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     nights: list<string>,
     *     rooms: list<array{
     *         id: int,
     *         code: string,
     *         label: string,
     *         sort: int,
     *         room_type: array{id: int, code: string, name: string},
     *         cells: list<array{
     *             night: string,
     *             state: string,
     *             claim: array{claim_group: string, reference: string|null, guest_surname: string|null, owner: string|null, holder_type: string, holder_id: int}|null
     *         }>
     *     }>,
     *     counts: array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>,
     *     occupancy: array{room_nights_available: int, room_nights_sold: int, pct: int},
     *     kpis: array{occupancy_pct: int, free_room_nights: int, nights_fully_sold: int, nights_below_threshold: int}
     * }
     */
    private function tally(Property $property, CarbonInterface $from, CarbonInterface $to): array
    {
        $stay = $this->range($from, $to);
        $nightList = [];

        foreach ($stay->eachNight() as $night) {
            $nightList[] = $night->toDateString();
        }

        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->where('status', RoomStatus::Active)
            ->with('roomType')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $claims = $this->claims($rooms, $stay);
        $byRoomNight = [];

        foreach ($claims as $claim) {
            if ($this->isExpiredHold($claim)) {
                continue;
            }

            $byRoomNight[$claim->room_id.'|'.$claim->night->toDateString()] = $claim;
        }

        /** @var array<string, array{total: int, free: int, held: int, sold: int, blocked: int}> $blank */
        $blank = [];

        foreach ($rooms as $room) {
            $code = $room->roomType->code;

            if (! isset($blank[$code])) {
                $blank[$code] = ['total' => 0, 'free' => 0, 'held' => 0, 'sold' => 0, 'blocked' => 0];
            }

            $blank[$code]['total']++;
        }

        $counts = [];
        $rows = [];
        $soldNights = 0;
        $availableNights = 0;
        $freeNights = 0;
        $fullySold = 0;
        $below = 0;
        $threshold = $this->threshold();

        foreach ($nightList as $night) {
            $counts[$night] = $blank;

            foreach ($counts[$night] as $code => $row) {
                $counts[$night][$code]['free'] = $row['total'];
            }
        }

        foreach ($rooms as $room) {
            $code = $room->roomType->code;
            $cells = [];

            foreach ($nightList as $night) {
                $found = $byRoomNight[$room->id.'|'.$night] ?? null;
                $claim = $found instanceof RoomNightClaim ? $found : null;
                $state = $this->state($claim);
                $cells[] = [
                    'night' => $night,
                    'state' => $state->value,
                    'claim' => $claim instanceof RoomNightClaim ? $this->summary($claim) : null,
                ];

                $bucket = match ($state) {
                    RoomNightState::Free => 'free',
                    RoomNightState::Held => 'held',
                    RoomNightState::Sold => 'sold',
                    RoomNightState::Blocked => 'blocked',
                };

                if ($bucket !== 'free') {
                    $counts[$night][$code]['free']--;
                    $counts[$night][$code][$bucket]++;
                }
            }

            $rows[] = [
                'id' => $room->id,
                'code' => $room->code,
                'label' => $room->label,
                'sort' => $room->sort,
                'room_type' => [
                    'id' => $room->roomType->id,
                    'code' => $room->roomType->code,
                    'name' => $room->roomType->name,
                ],
                'cells' => $cells,
            ];
        }

        foreach ($nightList as $night) {
            $free = 0;
            $held = 0;
            $sold = 0;

            foreach ($counts[$night] as $row) {
                $free += $row['free'];
                $held += $row['held'];
                $sold += $row['sold'];
            }

            $soldNights += $sold;
            $availableNights += $free + $held + $sold;
            $freeNights += $free;

            if ($free === 0 && $held === 0 && $sold > 0) {
                $fullySold++;
            }

            if ($free > 0 && $free <= $threshold) {
                $below++;
            }
        }

        $pct = $availableNights === 0 ? 0 : intdiv($soldNights * 100, $availableNights);

        return [
            'from' => $stay->checkIn()->toDateString(),
            'to' => $stay->checkOut()->toDateString(),
            'nights' => $nightList,
            'rooms' => $rows,
            'counts' => $counts,
            'occupancy' => [
                'room_nights_available' => $availableNights,
                'room_nights_sold' => $soldNights,
                'pct' => $pct,
            ],
            'kpis' => [
                'occupancy_pct' => $pct,
                'free_room_nights' => $freeNights,
                'nights_fully_sold' => $fullySold,
                'nights_below_threshold' => $below,
            ],
        ];
    }

    /**
     * @param  EloquentCollection<int, Room>  $rooms
     * @return EloquentCollection<int, RoomNightClaim>
     */
    private function claims(EloquentCollection $rooms, StayDates $stay): EloquentCollection
    {
        $ids = $rooms->modelKeys();

        if ($ids === []) {
            return new EloquentCollection;
        }

        return RoomNightClaim::query()
            ->whereIn('room_id', $ids)
            ->whereBetween('night', [$stay->checkIn()->toDateString(), $stay->lastNight()->toDateString()])
            ->whereNull('released_at')
            ->with(['holder' => function (Relation $morph): void {
                if ($morph instanceof MorphTo) {
                    $morph->morphWith([
                        Booking::class => ['owner', 'guests'],
                    ]);
                }
            }])
            ->get();
    }

    private function range(CarbonInterface $from, CarbonInterface $to): StayDates
    {
        $start = CarbonImmutable::parse($from->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($to->toDateString())->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('The end date must be after the start date.');
        }

        $stay = StayDates::of($start->toDateString(), $end->toDateString());

        if ($stay->nights() > self::MAX_NIGHTS) {
            throw new InvalidArgumentException('The calendar range cannot exceed 62 nights.');
        }

        return $stay;
    }

    private function state(?RoomNightClaim $claim): RoomNightState
    {
        if (! $claim instanceof RoomNightClaim) {
            return RoomNightState::Free;
        }

        return match ($claim->kind) {
            ClaimKind::Hold => RoomNightState::Held,
            ClaimKind::Booking => RoomNightState::Sold,
            ClaimKind::Block => RoomNightState::Blocked,
        };
    }

    /**
     * @return array{claim_group: string, reference: string|null, guest_surname: string|null, owner: string|null, holder_type: string, holder_id: int}
     */
    private function summary(RoomNightClaim $claim): array
    {
        $holder = $claim->holder;

        return [
            'claim_group' => $claim->claim_group,
            'reference' => $this->reference($holder),
            'guest_surname' => $this->guestSurname($holder),
            'owner' => $this->ownerName($holder),
            'holder_type' => $claim->holder_type,
            'holder_id' => (int) $claim->holder_id,
        ];
    }

    private function reference(?Model $holder): ?string
    {
        if (! $holder instanceof Model) {
            return null;
        }

        $reference = $holder->getAttribute('reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    private function guestSurname(?Model $holder): ?string
    {
        if (! $holder instanceof Booking) {
            return null;
        }

        $lead = $holder->guests->first(fn (Guest $guest): bool => $guest->is_lead && $guest->last_name !== '');

        if ($lead instanceof Guest) {
            return $lead->last_name;
        }

        $named = $holder->guests->first(fn (Guest $guest): bool => $guest->last_name !== '');

        return $named instanceof Guest ? $named->last_name : null;
    }

    private function ownerName(?Model $holder): ?string
    {
        if (! $holder instanceof Booking) {
            return null;
        }

        return $holder->owner->name;
    }

    private function isExpiredHold(RoomNightClaim $claim): bool
    {
        return $claim->kind === ClaimKind::Hold
            && $claim->expires_at !== null
            && $claim->expires_at->lt(now());
    }

    private function threshold(): int
    {
        if (! $this->config->has(ConfigKind::EngineSettings)) {
            return EngineSettingsDocument::fromArray(EngineSettingsDocument::initial())->availability->lowAvailabilityThreshold;
        }

        return $this->config->engineSettings()->availability->lowAvailabilityThreshold;
    }
}
