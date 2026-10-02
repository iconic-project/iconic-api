<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\RoomTypes\CreateRoomType;
use App\Actions\RoomTypes\DeactivateRoomType;
use App\Actions\RoomTypes\UpdateRoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\StoreRoomTypeRequest;
use App\Http\Requests\Rms\UpdateRoomTypeRequest;
use App\Http\Resources\Rms\RoomTypeResource;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoomTypeController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RoomType::class);

        $types = $property->roomTypes()->orderBy('sort')->orderBy('code')->get();

        return RoomTypeResource::collection($types);
    }

    public function store(StoreRoomTypeRequest $request, Property $property, CreateRoomType $action): JsonResponse
    {
        $this->authorize('create', RoomType::class);

        return (new RoomTypeResource($action->handle($property, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRoomTypeRequest $request, RoomType $roomType, UpdateRoomType $action): RoomTypeResource
    {
        $this->authorize('update', $roomType);

        return new RoomTypeResource($action->handle($roomType, $request->validated()));
    }

    public function deactivate(RoomType $roomType, DeactivateRoomType $action): RoomTypeResource
    {
        $this->authorize('update', $roomType);

        return new RoomTypeResource($action->handle($roomType));
    }
}
