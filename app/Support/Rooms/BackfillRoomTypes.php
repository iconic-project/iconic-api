<?php

declare(strict_types=1);

namespace App\Support\Rooms;

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use Illuminate\Support\Facades\DB;

final class BackfillRoomTypes
{
    public function __construct(private readonly CurrentConfig $config) {}

    public function pending(): void
    {
        $pending = DB::table('rooms')->whereNull('room_type_id')->get();

        if ($pending->isEmpty()) {
            return;
        }

        $maxOccupancy = $this->maxOccupancy();

        foreach ($pending->groupBy(fn (object $row): string => $row->property_id.'|'.$row->category) as $rows) {
            $first = $rows->first();

            if (! is_object($first)) {
                continue;
            }

            $type = $this->ensure((int) $first->property_id, (string) $first->category, $maxOccupancy);

            DB::table('rooms')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update(['room_type_id' => $type->id]);
        }
    }

    /**
     * Guest cap stored before the per-room key was removed. The published
     * document is read raw because the typed settings no longer keep it.
     * The value 3 is that key's original initial().
     */
    public function maxOccupancy(): int
    {
        $key = 'max_per_cab'.'in';

        if ($this->config->has(ConfigKind::EngineSettings)) {
            $document = $this->config->version(ConfigKind::EngineSettings)->document;
            $stored = $document['guests'][$key] ?? null;

            if (is_numeric($stored)) {
                return (int) $stored;
            }
        }

        return 3;
    }

    public function ensure(int $propertyId, string $category, int $maxOccupancy): RoomType
    {
        $name = match ($category) {
            'SUITE' => 'Suite',
            'OWNER' => "Owner's Suite",
            default => $category,
        };

        return RoomType::query()->firstOrCreate(
            [
                'property_id' => $propertyId,
                'code' => $category,
            ],
            [
                'name' => $name,
                'base_occupancy' => $maxOccupancy,
                'max_occupancy' => $maxOccupancy,
                'max_adults' => $maxOccupancy,
                'max_children' => $maxOccupancy,
                'waitlist_enabled' => true,
                'sort' => $category === 'OWNER' ? 1 : 0,
                'status' => RoomTypeStatus::Active,
            ],
        );
    }
}
