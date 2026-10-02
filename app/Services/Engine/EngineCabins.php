<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Enums\CabinState;
use App\Models\Departure;
use App\Services\Inventory\Availability;
use App\Support\Inventory\DepartureSnapshot;
use Illuminate\Support\Facades\Cache;

final class EngineCabins
{
    public function __construct(private Availability $availability) {}

    /**
     * @return list<array{code: string, category: string, bookable: bool}>
     */
    public function for(Departure $departure): array
    {
        $departure->loadMissing(['property.cabins', 'itinerary']);

        /** @var list<array{code: string, category: string, bookable: bool}> $cabins */
        $cabins = Cache::remember(
            EngineFeedVersion::cabinsKey((int) $departure->id),
            5,
            fn (): array => $this->assemble($departure),
        );

        return $cabins;
    }

    /**
     * @return list<array{code: string, category: string, bookable: bool}>
     */
    private function assemble(Departure $departure): array
    {
        $snapshots = $this->availability->forDepartures(collect([$departure]));
        $snapshot = $snapshots[$departure->id] ?? null;

        if (! $snapshot instanceof DepartureSnapshot) {
            return [];
        }

        $rows = [];

        foreach ($snapshot->cabins as $cabinRow) {
            $rows[] = [
                'code' => $cabinRow['cabin']['label'],
                'category' => $cabinRow['cabin']['category'],
                'bookable' => $cabinRow['state'] === CabinState::Free->value,
            ];
        }

        return $rows;
    }
}
