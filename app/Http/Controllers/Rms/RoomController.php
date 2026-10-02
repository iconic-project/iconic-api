<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Rooms\CreateRoom;
use App\Actions\Rooms\DeactivateRoom;
use App\Actions\Rooms\UpdateRoom;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\StoreRoomRequest;
use App\Http\Requests\Rms\UpdateRoomRequest;
use App\Http\Resources\Rms\RoomResource;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoomController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Room::class);

        $rooms = $property->rooms()->with('roomType')->orderBy('sort')->orderBy('code')->get();

        return RoomResource::collection($rooms);
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
}
