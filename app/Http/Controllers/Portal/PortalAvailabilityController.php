<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\DepartureStatus;
use App\Enums\ItineraryStatus;
use App\Http\Requests\Portal\IndexPortalAvailabilityRequest;
use App\Http\Resources\Portal\PortalAvailabilityResource;
use App\Models\Departure;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PortalAvailabilityController extends PortalController
{
    public function index(
        IndexPortalAvailabilityRequest $request,
        Availability $availability,
        CurrentConfig $config,
    ): AnonymousResourceCollection {
        $agency = $this->agency($request);
        $perPage = $request->integer('per_page', 50);

        $page = Departure::query()
            ->with(['property', 'itinerary'])
            ->where('status', '!=', DepartureStatus::Hidden)
            ->whereHas('itinerary', fn (Builder $query) => $query->where('status', ItineraryStatus::Published))
            ->when(
                is_string($request->validated('from')),
                fn (Builder $query) => $query->whereDate('date', '>=', (string) $request->validated('from')),
            )
            ->when(
                is_string($request->validated('to')),
                fn (Builder $query) => $query->whereDate('date', '<=', (string) $request->validated('to')),
            )
            ->when(
                is_string($request->validated('property')),
                fn (Builder $query) => $query->whereHas('property', fn (Builder $inner) => $inner->where('code', $request->validated('property'))),
            )
            ->when(
                is_string($request->validated('itinerary')),
                fn (Builder $query) => $query->whereHas('itinerary', fn (Builder $inner) => $inner->where('code', $request->validated('itinerary'))),
            )
            ->orderBy('date')
            ->paginate($perPage);

        $snapshots = $availability->forDepartures($page->getCollection());
        $rates = $config->rates();

        $page->through(fn (Departure $departure): array => [
            'departure' => $departure,
            'snapshot' => $snapshots[$departure->id],
            'agency' => $agency,
            'rates' => $rates,
        ]);

        return PortalAvailabilityResource::collection($page);
    }
}
