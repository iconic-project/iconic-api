<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\EngineCalendarRequest;
use App\Http\Resources\Engine\EngineCalendarResource;
use App\Services\Engine\EngineCalendar;

final class CalendarController extends Controller
{
    public function __invoke(EngineCalendarRequest $request, EngineCalendar $calendar): EngineCalendarResource
    {
        $validated = $request->validated();

        return new EngineCalendarResource($calendar->month(
            (string) $validated['from'],
            (int) $validated['months'],
            (int) $validated['adults'],
            (int) ($validated['children'] ?? 0),
        ));
    }
}
