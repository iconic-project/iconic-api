<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\DeactivateRoom;
use App\Actions\Rooms\UpdateRoom;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexRoomsRequest;
use App\Http\Requests\Rms\StoreRoomRequest;
use App\Http\Requests\Rms\UpdateRoomRequest;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\RoomResource;
use App\Models\Property;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoomController extends Controller
{
    public function index(IndexRoomsRequest $request, Property $property): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Room::class);

        $rooms = $property->rooms()->with('roomType')->orderBy('sort')->orderBy('code');
        $from = $request->validated('free_from');
        $to = $request->validated('free_to');

        if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
            $lastNight = CarbonImmutable::parse($to)->subDay()->toDateString();
            $rooms
                ->where('status', RoomStatus::Active)
                ->whereDoesntHave('nightClaims', function (Builder $claims) use ($from, $lastNight): void {
                    $claims->whereNull('released_at')
                        ->whereDate('night', '>=', $from)
                        ->whereDate('night', '<=', $lastNight)
                        ->where(function (Builder $hold): void {
                            $hold->whereNull('expires_at')
                                ->orWhere('expires_at', '>=', now());
                        });
                });
        }

        return RoomResource::collection($rooms->get());
    }

    public function store(StoreRoomRequest $request, Property $property, CreateRoom $action): JsonResponse
    {
        $this->authorize('create', Room::class);

        return (new RoomResource($action->handle($property, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoomRequest $request, Room $room, UpdateRoom $action): RoomResource
    {
        $this->authorize('update', $room);

        return new RoomResource($action->handle($room, $request->validated()));
    }

    public function deactivate(Room $room, DeactivateRoom $action): RoomResource
    {
        $this->authorize('update', $room);

        return new RoomResource($action->handle($room));
    }

    public function history(Room $room): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $room);

        $entries = $room->history()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ChangeHistoryResource::collection($entries);
    }
}
