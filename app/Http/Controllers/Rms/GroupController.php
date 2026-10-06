<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexGroupsRequest;
use App\Http\Resources\Rms\GroupResource;
use App\Models\Booking;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class GroupController extends Controller
{
    public function index(IndexGroupsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $groups = Group::query()
            ->visibleTo($actor)
            ->with([
                'coordinator',
                'bookings' => fn ($bookings) => $bookings->withLedgerAggregates()->with(['room', 'property']),
            ])
            ->when(
                $request->filled('from'),
                fn (Builder $query) => $query->whereHas(
                    'bookings',
                    fn (Builder $bookings) => $bookings->whereDate('check_in', '>=', (string) $request->validated('from')),
                ),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query) => $query->whereHas(
                    'bookings',
                    fn (Builder $bookings) => $bookings->whereDate('check_in', '<=', (string) $request->validated('to')),
                ),
            )
            ->orderBy('reference')
            ->get();

        return GroupResource::collection($groups);
    }
}
