<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CabinCategory;
use App\Models\Cabin;
use App\Models\Property;
use Illuminate\Database\Seeder;

final class InventorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ANAMARA', 'ANATIVA'] as $code) {
            $property = Property::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            );

            foreach ($this->cabins() as $cabin) {
                Cabin::query()->firstOrCreate(
                    [
                        'property_id' => $property->id,
                        'code' => $cabin['code'],
                    ],
                    [
                        'label' => $cabin['label'],
                        'category' => $cabin['category'],
                        'sort' => $cabin['sort'],
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{code: string, label: string, category: CabinCategory, sort: int}>
     */
    private function cabins(): array
    {
        $cabins = [];

        for ($index = 1; $index <= 8; $index++) {
            $cabins[] = [
                'code' => 'S'.$index,
                'label' => 'Suite 0'.$index,
                'category' => CabinCategory::Suite,
                'sort' => $index,
            ];
        }

        $cabins[] = [
            'code' => 'OWNER',
            'label' => "Owner's Suite",
            'category' => CabinCategory::Owner,
            'sort' => 9,
        ];

        return $cabins;
    }
}
