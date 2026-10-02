<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Properties\UpdatePropertyContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\UpdatePropertyRequest;
use App\Http\Resources\Rms\PropertyResource;
use App\Models\Property;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
