<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Waitlist\AddWaitlistEntry;
use App\Enums\WaitlistSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Engine\StoreEngineWaitlistRequest;
use App\Http\Resources\Engine\EngineWaitlistResource;
use App\Models\RoomType;
use App\Services\Engine\EnginePropertyFeed;
use App\Support\Content\Completeness;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class WaitlistController extends Controller
{
    #[DocumentedResponse(status: 201, type: EngineWaitlistResource::class)]
    public function __invoke(
        StoreEngineWaitlistRequest $request,
        EnginePropertyFeed $feed,
        AddWaitlistEntry $action,
    ): JsonResponse {
        $validated = $request->validated();
        $property = $feed->property();
        $code = (string) $validated['room_type'];
        $type = $property->roomTypes->first(
            fn (RoomType $candidate): bool => $candidate->code === $code && Completeness::engineVisible($candidate),
        );

        abort_unless($type instanceof RoomType, Response::HTTP_NOT_FOUND);

        $entry = $action->handle([
            'room_type_id' => $type->id,
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'client' => is_array($validated['contact'] ?? null) ? $validated['contact'] : [],
            'adults' => $validated['adults'],
            'children' => $validated['children'],
            'notes' => $validated['notes'] ?? null,
            'source' => WaitlistSource::Engine,
            'session_id' => $validated['session_id'] ?? null,
        ]);

        return (new EngineWaitlistResource($entry))->response()->setStatusCode(201);
    }
}
