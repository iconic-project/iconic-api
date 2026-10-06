<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\RoomTypes\AddRoomTypePhoto;
use App\Actions\RoomTypes\CreateRoomType;
use App\Actions\RoomTypes\DeactivateRoomType;
use App\Actions\RoomTypes\UpdateRoomTypeContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\StoreContentImageRequest;
use App\Http\Requests\Rms\StoreRoomTypeRequest;
use App\Http\Requests\Rms\UpdateRoomTypeRequest;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\RoomTypeResource;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

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

    public function update(UpdateRoomTypeRequest $request, RoomType $roomType, UpdateRoomTypeContent $action): RoomTypeResource
    {
        $this->authorize('update', $roomType);

        return new RoomTypeResource($action->handle($roomType, $request->validated()));
    }

    public function photo(StoreContentImageRequest $request, RoomType $roomType, AddRoomTypePhoto $action): RoomTypeResource
    {
        $this->authorize('update', $roomType);

        $file = $request->file('image');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        return new RoomTypeResource($action->handle($roomType, $file, (string) $request->validated('alt')));
    }

    public function history(RoomType $roomType): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $roomType);

        $entries = $roomType->history()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ChangeHistoryResource::collection($entries);
    }

    public function deactivate(RoomType $roomType, DeactivateRoomType $action): RoomTypeResource
    {
        $this->authorize('update', $roomType);

        return new RoomTypeResource($action->handle($roomType));
    }
}
