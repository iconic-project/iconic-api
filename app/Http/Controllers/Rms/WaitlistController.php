<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Waitlist\AddWaitlistEntry;
use App\Actions\Waitlist\NotifyWaitlistEntry;
use App\Actions\Waitlist\RemoveWaitlistEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexWaitlistRequest;
use App\Http\Requests\Rms\NotifyWaitlistEntryRequest;
use App\Http\Requests\Rms\RemoveWaitlistEntryRequest;
use App\Http\Requests\Rms\StoreWaitlistEntryRequest;
use App\Http\Resources\Rms\WaitlistEntryResource;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Inventory\NightAvailability;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class WaitlistController extends Controller
{
    public function index(IndexWaitlistRequest $request, NightAvailability $availability): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WaitlistEntry::class);

        $entries = WaitlistEntry::query()
            ->with(['roomType', 'contact', 'notifiedBy'])
            ->when(
                ! $request->boolean('include_removed'),
                fn (Builder $query) => $query->active(),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $query) => $query->whereDate('check_out', '>', (string) $request->validated('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query) => $query->whereDate('check_in', '<=', (string) $request->validated('to')),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $ranks = $this->positions($entries->pluck('room_type_id')->unique()->filter()->all());
        $bookable = [];

        foreach ($entries as $entry) {
            $entry->queuePosition = $entry->isActive() ? ($ranks[$entry->id] ?? null) : null;
            $key = $entry->room_type_id.'|'.$entry->check_in->toDateString().'|'.$entry->check_out->toDateString();

            if (! array_key_exists($key, $bookable)) {
                $bookable[$key] = $availability->canBook(
                    $entry->roomType,
                    StayDates::of($entry->check_in, $entry->check_out),
                    1,
                )->ok;
            }

            $entry->roomIsAvailable = $bookable[$key];
        }

        return WaitlistEntryResource::collection($entries);
    }

    public function store(StoreWaitlistEntryRequest $request, AddWaitlistEntry $action): JsonResponse
    {
        $this->authorize('create', WaitlistEntry::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $entry = $action->handle($request->validated(), $actor);
        $entry->queuePosition = 1;
        $entry->roomIsAvailable = false;

        return (new WaitlistEntryResource($entry))->response()->setStatusCode(201);
    }

    public function notify(
        NotifyWaitlistEntryRequest $request,
        WaitlistEntry $entry,
        NotifyWaitlistEntry $action,
    ): WaitlistEntryResource {
        $this->authorize('notify', $entry);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new WaitlistEntryResource($action->handle($entry, $request->validated(), $actor));
    }

    public function remove(
        RemoveWaitlistEntryRequest $request,
        WaitlistEntry $entry,
        RemoveWaitlistEntry $action,
    ): WaitlistEntryResource {
        $this->authorize('remove', $entry);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new WaitlistEntryResource($action->handle($entry, $request->validated(), $actor));
    }

    /**
     * @param  list<int|string>  $roomTypeIds
     * @return array<int, int>
     */
    private function positions(array $roomTypeIds): array
    {
        if ($roomTypeIds === []) {
            return [];
        }

        $rows = DB::table('waitlist_entries')
            ->select('id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY room_type_id, check_in, check_out ORDER BY created_at, id) AS queue_position')
            ->whereNull('removed_at')
            ->whereIn('room_type_id', $roomTypeIds)
            ->get();

        $ranks = [];

        foreach ($rows as $row) {
            $ranks[(int) $row->id] = (int) $row->queue_position;
        }

        return $ranks;
    }
}
