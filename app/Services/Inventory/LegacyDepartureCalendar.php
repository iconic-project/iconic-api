<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Departure;
use App\Models\Property;
use App\Support\Inventory\Snapshots;
use Carbon\CarbonInterface;

/**
 * Departure columns for the yacht calendar.
 *
 * @deprecated Panel 17-07 reads the night grid. Sprint 19 removes this.
 */
final class LegacyDepartureCalendar
{
    /**
     * @return array{
     *     departures: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function grid(CarbonInterface $from, CarbonInterface $to, ?int $propertyId): array
    {
        $properties = Property::query()
            ->with('cabins.roomType')
            ->when($propertyId !== null, fn ($query) => $query->whereKey($propertyId))
            ->orderBy('code')
            ->get();

        $departures = Departure::query()
            ->with(['property', 'itinerary'])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->orderBy('date')
            ->orderBy(
                Property::query()->select('code')->whereColumn('properties.id', 'departures.property_id'),
            )
            ->get();

        $snapshots = Snapshots::attach($departures);
        $rows = [];

        foreach ($properties as $property) {
            foreach ($property->cabins as $cabin) {
                $cells = [];

                foreach ($departures as $departure) {
                    if ($departure->property_id !== $property->id) {
                        continue;
                    }

                    $cabinRow = collect($snapshots[$departure->id]->cabins)
                        ->firstWhere('cabin.code', $cabin->code);

                    $cells[(string) $departure->id] = [
                        'state' => $cabinRow['state'] ?? 'FREE',
                        'claim' => $cabinRow['claim'] ?? null,
                    ];
                }

                $rows[] = [
                    'property' => [
                        'id' => $property->id,
                        'code' => $property->code,
                        'name' => $property->name,
                    ],
                    'cabin' => [
                        'id' => $cabin->id,
                        'code' => $cabin->code,
                        'label' => $cabin->label,
                        'category' => $cabin->roomType->code,
                        'sort' => $cabin->sort,
                    ],
                    'cells' => $cells,
                ];
            }
        }

        return [
            'departures' => $departures->map(fn (Departure $departure): array => [
                'id' => $departure->id,
                'reference' => $departure->reference,
                'date' => $departure->date->toDateString(),
                'property' => [
                    'id' => $departure->property->id,
                    'code' => $departure->property->code,
                    'name' => $departure->property->name,
                ],
                'itinerary' => [
                    'id' => $departure->itinerary->id,
                    'code' => $departure->itinerary->code,
                    'name' => $departure->itinerary->name,
                ],
                'festive' => $departure->festive,
                'status' => $departure->status->value,
            ])->values()->all(),
            'rows' => $rows,
        ];
    }
}
