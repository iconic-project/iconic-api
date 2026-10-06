<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Support\Rooms\BackfillRoomTypes;
use Illuminate\Database\Seeder;

final class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $types = app(BackfillRoomTypes::class);
        $maxOccupancy = $types->maxOccupancy();

        foreach (['ANAMARA', 'ANATIVA'] as $code) {
            $property = Property::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            );

            foreach ($this->cabins() as $cabin) {
                $type = $types->ensure($property->id, $cabin['category'], $maxOccupancy);

                Room::query()->firstOrCreate(
                    [
                        'property_id' => $property->id,
                        'code' => $cabin['code'],
                    ],
                    [
                        'label' => $cabin['label'],
                        'room_type_id' => $type->id,
                        'sort' => $cabin['sort'],
                        'status' => RoomStatus::Active,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{code: string, label: string, category: string, sort: int}>
     */
    private function cabins(): array
    {
        $cabins = [];

        for ($index = 1; $index <= 8; $index++) {
            $cabins[] = [
                'code' => 'S'.$index,
                'label' => 'Suite 0'.$index,
                'category' => 'STD',
                'sort' => $index,
            ];
        }

        $cabins[] = [
            'code' => 'OWNER',
            'label' => "Owner's Suite",
            'category' => 'STE',
            'sort' => 9,
        ];

        return $cabins;
    }
}
