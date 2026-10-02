<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexCalendarRequest;
use App\Http\Resources\Rms\CalendarGridResource;
use App\Models\Departure;
use App\Models\Property;
use App\Support\Inventory\Snapshots;

final class CalendarController extends Controller
{
    public function __invoke(IndexCalendarRequest $request): CalendarGridResource
    {
        $this->authorize('viewAny', Departure::class);

        [$from, $to] = $request->range();

        $properties = Property::query()
            ->with('cabins')
            ->when($request->filled('property_id'), fn ($query) => $query->whereKey($request->validated('property_id')))
            ->orderBy('code')
            ->get();

        $departures = Departure::query()
            ->with(['property', 'itinerary'])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($request->filled('property_id'), fn ($query) => $query->where('property_id', $request->validated('property_id')))
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
                        'category' => $cabin->category->value,
                        'sort' => $cabin->sort,
                    ],
                    'cells' => $cells,
                ];
            }
        }

        return new CalendarGridResource([
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
        ]);
    }
}
