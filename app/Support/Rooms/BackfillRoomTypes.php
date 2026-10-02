<?php

declare(strict_types=1);

namespace App\Support\Rooms;

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\EngineSettingsDocument;
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

        $maxPerCabin = $this->maxPerCabin();

        foreach ($pending->groupBy(fn (object $row): string => $row->property_id.'|'.$row->category) as $rows) {
            $first = $rows->first();

            if (! is_object($first)) {
                continue;
            }

            $type = $this->ensure((int) $first->property_id, (string) $first->category, $maxPerCabin);

            DB::table('rooms')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update(['room_type_id' => $type->id]);
        }
    }

    public function maxPerCabin(): int
    {
        if (! $this->config->has(ConfigKind::EngineSettings)) {
            return (int) EngineSettingsDocument::initial()['guests']['max_per_cabin'];
        }

        return $this->config->engineSettings()->guests->maxPerCabin;
    }

    public function ensure(int $propertyId, string $category, int $maxPerCabin): RoomType
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
                'base_occupancy' => $maxPerCabin,
                'max_occupancy' => $maxPerCabin,
                'max_adults' => $maxPerCabin,
                'max_children' => $maxPerCabin,
                'waitlist_enabled' => true,
                'sort' => $category === 'OWNER' ? 1 : 0,
                'status' => RoomTypeStatus::Active,
            ],
        );
    }
}
