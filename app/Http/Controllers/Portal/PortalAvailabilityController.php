<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Requests\Portal\IndexPortalAvailabilityRequest;
use App\Http\Requests\Portal\IndexPortalCalendarRequest;
use App\Http\Resources\Portal\PortalAvailabilityResource;
use App\Http\Resources\Portal\PortalCalendarResource;
use App\Services\Engine\EngineCalendar;
use App\Services\Engine\EngineStayAvailability;
use App\Support\Portal\PortalNetPrices;

final class PortalAvailabilityController extends PortalController
{
    public function index(
        IndexPortalAvailabilityRequest $request,
        EngineStayAvailability $availability,
        PortalNetPrices $net,
    ): PortalAvailabilityResource {
        $validated = $request->validated();
        /** @var list<int> $ages */
        $ages = array_map(intval(...), $validated['child_ages'] ?? []);

        return new PortalAvailabilityResource($net->availability(
            $this->agency($request),
            $availability->search(
                (string) $validated['check_in'],
                (string) $validated['check_out'],
                (int) $validated['adults'],
                $ages,
                (int) ($validated['rooms'] ?? 1),
            ),
        ));
    }

    public function calendar(
        IndexPortalCalendarRequest $request,
        EngineCalendar $calendar,
        PortalNetPrices $net,
    ): PortalCalendarResource {
        $validated = $request->validated();

        return new PortalCalendarResource($net->calendar(
            $this->agency($request),
            $calendar->month(
                (string) $validated['from'],
                (int) $validated['months'],
                (int) $validated['adults'],
                (int) ($validated['children'] ?? 0),
            ),
        ));
    }
}
