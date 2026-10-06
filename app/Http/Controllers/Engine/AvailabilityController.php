<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\EngineAvailabilityRequest;
use App\Http\Resources\Engine\EngineAvailabilityResource;
use App\Services\Engine\EngineStayAvailability;

final class AvailabilityController extends Controller
{
    public function __invoke(EngineAvailabilityRequest $request, EngineStayAvailability $availability): EngineAvailabilityResource
    {
        $validated = $request->validated();
        /** @var list<int> $ages */
        $ages = array_map(intval(...), $validated['child_ages'] ?? []);

        return new EngineAvailabilityResource($availability->search(
            (string) $validated['check_in'],
            (string) $validated['check_out'],
            (int) $validated['adults'],
            $ages,
            (int) ($validated['rooms'] ?? 1),
        ));
    }
}
