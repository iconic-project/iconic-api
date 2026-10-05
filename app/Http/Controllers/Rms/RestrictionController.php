<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Restrictions\SetStayRestrictions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexRestrictionsRequest;
use App\Http\Requests\Rms\SetStayRestrictionsRequest;
use App\Http\Resources\Rms\StayRestrictionResource;
use App\Models\StayRestriction;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class RestrictionController extends Controller
{
    public function index(IndexRestrictionsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', StayRestriction::class);

        return $this->payload(
            $request->integer('property_id'),
            (string) $request->validated('from'),
            (string) $request->validated('to'),
        );
    }

    public function update(SetStayRestrictionsRequest $request, SetStayRestrictions $action): JsonResponse
    {
        $this->authorize('set', StayRestriction::class);

        $actor = $request->user();
        $data = $request->validated();
        $action->handle($data, $actor instanceof User ? $actor : null);

        return $this->payload(
            (int) $data['property_id'],
            (string) $data['from'],
            (string) $data['to'],
        );
    }

    private function payload(int $propertyId, string $from, string $to): JsonResponse
    {
        $rows = StayRestriction::query()
            ->where('property_id', $propertyId)
            ->whereBetween('night', [$from, $to])
            ->orderBy('night')
            ->orderByRaw('room_type_id is null desc')
            ->orderBy('room_type_id')
            ->get();

        return response()->json([
            'property_id' => $propertyId,
            'from' => $from,
            'to' => $to,
            'range' => 'inclusive',
            'reason_order' => StayRestrictionResource::REASON_ORDER,
            'restrictions' => StayRestrictionResource::collection($rows)->resolve(),
        ]);
    }
}
