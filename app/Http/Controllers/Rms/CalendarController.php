<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexCalendarRequest;
use App\Http\Resources\Rms\CalendarGridResource;
use App\Http\Resources\Rms\NightCalendarResource;
use App\Models\Departure;
use App\Models\Property;
use App\Services\Inventory\LegacyDepartureCalendar;
use App\Services\Inventory\NightAvailability;

final class CalendarController extends Controller
{
    public function __construct(
        private readonly NightAvailability $nights,
        private readonly LegacyDepartureCalendar $departures,
    ) {}

    public function __invoke(IndexCalendarRequest $request): NightCalendarResource|CalendarGridResource
    {
        $this->authorize('viewAny', Departure::class);

        [$from, $to] = $request->range();

        if ($request->filled('property_id')) {
            $property = Property::query()->findOrFail($request->integer('property_id'));
            $grid = $this->nights->grid($property, $from, $to);

            return new NightCalendarResource($grid);
        }

        return new CalendarGridResource($this->departures->grid($from, $to, null));
    }
}
