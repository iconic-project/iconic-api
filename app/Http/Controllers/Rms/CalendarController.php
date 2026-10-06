<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexCalendarRequest;
use App\Http\Resources\Rms\NightCalendarResource;
use App\Models\Property;
use App\Services\Inventory\NightAvailability;

final class CalendarController extends Controller
{
    public function __construct(private readonly NightAvailability $nights) {}

    public function __invoke(IndexCalendarRequest $request): NightCalendarResource
    {
        $this->authorize('viewAny', Property::class);

        [$from, $to] = $request->range();
        $property = Property::query()->findOrFail($request->integer('property_id'));

        return new NightCalendarResource($this->nights->grid($property, $from, $to));
    }
}
