<?php

declare(strict_types=1);

namespace App\Actions\Blocks;

use App\Actions\Action;
use App\Enums\ClaimKind;
use App\Enums\ReferenceType;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Exceptions\CabinUnavailableException;
use App\Exceptions\RoomUnavailableException;
use App\Models\InternalBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Inventory\ClaimService;
use App\Services\References\ReferenceService;
use App\Support\Blocks\ConflictMessage;
use App\Support\History\History;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateInternalBlock extends Action
{
    public function __construct(
        private ClaimService $claims,
        private ReferenceService $references,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CabinUnavailableException
     */
    public function handle(array $data): InternalBlock
    {
        try {
            $stay = StayDates::of((string) $data['starts_on'], (string) $data['ends_on']);
            $resolved = $this->resolve($data);

            return $this->transaction(function () use ($data, $stay, $resolved): InternalBlock {
                $block = InternalBlock::query()->create([
                    'reference' => $this->references->next(ReferenceType::Block),
                    'property_id' => $resolved['property']->id,
                    'starts_on' => $stay->checkIn()->toDateString(),
                    'ends_on' => $stay->checkOut()->toDateString(),
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                ]);

                try {
                    $claimed = $resolved['rooms'] instanceof EloquentCollection
                        ? $this->claims->claim($stay, $resolved['rooms'], $block, ClaimKind::Block)
                        : $this->claims->claimType($stay, $resolved['type'], $resolved['count'], $block, ClaimKind::Block);
                } catch (RoomUnavailableException $exception) {
                    throw $this->conflict($stay, $resolved['search'], $exception);
                }

                $roomIds = $claimed->pluck('room_id')->unique()->values()->all();

                History::record($block, 'block.created', after: [
                    'property_id' => $block->property_id,
                    'starts_on' => $block->starts_on->toDateString(),
                    'ends_on' => $block->ends_on->toDateString(),
                    'reason' => $block->reason->value,
                    'notes' => $block->notes,
                    'room_ids' => $roomIds,
                ]);

                return $block;
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'starts_on' => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{property: Property, rooms: EloquentCollection<int, Room>|null, type: RoomType, count: int, search: EloquentCollection<int, Room>}
     */
    private function resolve(array $data): array
    {
        if (isset($data['rooms'])) {
            $rooms = $this->rooms($data['rooms']);

            return [
                'property' => $rooms->firstOrFail()->property,
                'rooms' => $rooms,
                'type' => $rooms->firstOrFail()->roomType,
                'count' => $rooms->count(),
                'search' => $rooms,
            ];
        }

        $type = RoomType::query()->with('property')->findOrFail((int) $data['room_type_id']);

        if ($type->status !== RoomTypeStatus::Active) {
            throw ValidationException::withMessages([
                'room_type_id' => [$type->name.' is not active.'],
            ]);
        }

        return [
            'property' => $type->property,
            'rooms' => null,
            'type' => $type,
            'count' => (int) $data['count'],
            'search' => Room::query()
                ->where('room_type_id', $type->id)
                ->where('status', RoomStatus::Active)
                ->orderBy('sort')
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * @return EloquentCollection<int, Room>
     */
    private function rooms(mixed $raw): EloquentCollection
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'rooms' => ['Choose at least one room.'],
            ]);
        }

        $ids = [];

        foreach ($raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));

        $rooms = Room::query()
            ->with(['property', 'roomType'])
            ->whereIn('id', $ids)
            ->get();

        if ($rooms->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'rooms' => ['Choose rooms that exist.'],
            ]);
        }

        if ($rooms->pluck('property_id')->unique()->count() !== 1) {
            throw ValidationException::withMessages([
                'rooms' => ['Rooms must belong to one property.'],
            ]);
        }

        $inactive = $rooms->first(fn (Room $room): bool => $room->status !== RoomStatus::Active);

        if ($inactive instanceof Room) {
            throw ValidationException::withMessages([
                'rooms' => [$inactive->label.' is not active.'],
            ]);
        }

        return $rooms->sortBy([['sort', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * @param  EloquentCollection<int, Room>  $rooms
     */
    private function conflict(StayDates $stay, EloquentCollection $rooms, RoomUnavailableException $exception): CabinUnavailableException
    {
        $claim = $this->firstConflict($stay, $rooms);

        if (! $claim instanceof RoomNightClaim) {
            return new CabinUnavailableException([], $exception->getMessage());
        }

        $reference = $this->holderReference($claim->holder);

        return new CabinUnavailableException(
            [[
                'cabin' => [
                    'id' => $claim->room->id,
                    'code' => $claim->room->code,
                    'label' => $claim->room->label,
                ],
                'held_by' => [
                    'kind' => $claim->kind->value,
                    'holder_type' => $claim->holder_type,
                    'reference' => $reference,
                ],
            ]],
            ConflictMessage::line($claim->room->label, $claim->night, $claim->kind, $reference),
        );
    }

    /**
     * @param  EloquentCollection<int, Room>  $rooms
     */
    private function firstConflict(StayDates $stay, EloquentCollection $rooms): ?RoomNightClaim
    {
        if ($rooms->isEmpty()) {
            return null;
        }

        $claims = RoomNightClaim::query()
            ->whereIn('room_id', $rooms->modelKeys())
            ->whereDate('night', '>=', $stay->checkIn()->toDateString())
            ->whereDate('night', '<=', $stay->lastNight()->toDateString())
            ->whereNull('released_at')
            ->where(function ($query): void {
                $query->where('kind', '!=', ClaimKind::Hold->value)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->with(['room', 'holder'])
            ->get();

        $first = $claims->sortBy([
            fn (RoomNightClaim $claim): string => $claim->night->toDateString(),
            fn (RoomNightClaim $claim): int => $claim->room->sort,
            fn (RoomNightClaim $claim): int => $claim->room->id,
        ])->first();

        return $first instanceof RoomNightClaim ? $first : null;
    }

    private function holderReference(mixed $holder): ?string
    {
        if (! $holder instanceof Model) {
            return null;
        }

        $reference = $holder->getAttribute('reference');

        return is_string($reference) && $reference !== '' ? $reference : null;
    }
}
