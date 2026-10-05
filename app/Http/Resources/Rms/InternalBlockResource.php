<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\InternalBlock;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\User;
use App\Support\Blocks\ScopeSummary;
use App\Support\Iso;
use App\Support\Stays\StayDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin InternalBlock
 */
class InternalBlockResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     property: array{id: int, code: string, name: string},
     *     starts_on: string,
     *     ends_on: string,
     *     nights: int,
     *     reason: string,
     *     reason_label: string,
     *     notes: string|null,
     *     scope_summary: string,
     *     rooms: list<array{id: int, code: string, label: string}>,
     *     created_by: array{id: int, name: string}|null,
     *     created_at: string|null,
     *     released_at: string|null,
     *     released_by: array{id: int, name: string}|null,
     *     release_note: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'property',
            'createdBy',
            'releasedBy',
            'claims.room',
        ]);

        $rooms = $this->rooms();
        $labels = $rooms->map(fn (Room $room): string => $room->label)->values()->all();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'property' => [
                'id' => $this->property->id,
                'code' => $this->property->code,
                'name' => $this->property->name,
            ],
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'nights' => StayDates::of($this->starts_on, $this->ends_on)->nights(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'notes' => $this->notes,
            'scope_summary' => ScopeSummary::format($labels, $this->starts_on, $this->ends_on),
            'rooms' => $rooms->map(fn (Room $room): array => [
                'id' => $room->id,
                'code' => $room->code,
                'label' => $room->label,
            ])->values()->all(),
            'created_by' => $this->actorPayload($this->createdBy),
            'created_at' => Iso::utc($this->created_at),
            'released_at' => $this->released_at !== null ? Iso::utc($this->released_at) : null,
            'released_by' => $this->actorPayload($this->releasedBy),
            'release_note' => $this->release_note,
        ];
    }

    /**
     * @return Collection<int, Room>
     */
    private function rooms(): Collection
    {
        return $this->claims
            ->map(fn (RoomNightClaim $claim): Room => $claim->room)
            ->unique('id')
            ->sortBy([['sort', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function actorPayload(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
