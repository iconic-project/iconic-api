<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Properties\ReplacePropertyHero;
use App\Actions\Properties\UpdatePropertyContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\StoreContentImageRequest;
use App\Http\Requests\Rms\UpdatePropertyRequest;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\PropertyResource;
use App\Models\Property;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

final class PropertyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Property::class);

        $properties = Property::query()
            ->with('rooms.roomType')
            ->orderBy('code')
            ->get();

        return PropertyResource::collection($properties);
    }

    public function show(Property $property): PropertyResource
    {
        $this->authorize('view', $property);

        return new PropertyResource($property);
    }

    public function update(UpdatePropertyRequest $request, Property $property, UpdatePropertyContent $action): PropertyResource
    {
        $this->authorize('update', $property);

        return new PropertyResource($action->handle($property, $request->validated()));
    }

    public function hero(StoreContentImageRequest $request, Property $property, ReplacePropertyHero $action): PropertyResource
    {
        $this->authorize('update', $property);

        $file = $request->file('image');

        if (! $file instanceof UploadedFile) {
            abort(422);
        }

        return new PropertyResource($action->handle($property, $file, (string) $request->validated('alt')));
    }

    public function history(Property $property): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $property);

        $entries = $property->history()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ChangeHistoryResource::collection($entries);
    }
}
