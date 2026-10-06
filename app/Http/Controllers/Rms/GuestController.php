<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Guests\AddGuest;
use App\Actions\Guests\RemoveGuest;
use App\Actions\Guests\UpdateGuest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\SaveGuestRequest;
use App\Http\Resources\Rms\GuestResource;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\User;
use App\Support\Guests\GuestIssues;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class GuestController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\GuestResource>, complete_count: int, total: int, png_known_total: int, png_pending_count: int, max: int, can_add: bool, issues: list<array{severity: string, code: string, guest_id: int|null, message: string}>}',
    )]
    public function index(Booking $booking, GuestIssues $issues): AnonymousResourceCollection
    {
        $this->authorize('view', $booking);

        $booking->load(['property', 'guests']);
        $summary = $issues->summary($booking);

        return GuestResource::collection($booking->guests->sortBy('position')->values())
            ->additional([
                'complete_count' => $summary['complete_count'],
                'total' => $summary['total'],
                'png_known_total' => $summary['png_known_total'],
                'png_pending_count' => $summary['png_pending_count'],
                'max' => $summary['max'],
                'can_add' => $summary['can_add'],
                'issues' => $issues->for($booking),
            ]);
    }

    public function store(SaveGuestRequest $request, Booking $booking, AddGuest $action): JsonResponse
    {
        $this->authorize('updateGuests', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $guest = $action->handle($booking, $request->validated(), $actor);

        return (new GuestResource($guest))->response()->setStatusCode(201);
    }

    public function update(SaveGuestRequest $request, Guest $guest, UpdateGuest $action): GuestResource
    {
        $this->authorize('update', $guest);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new GuestResource($action->handle($guest, $request->validated(), $actor));
    }

    public function destroy(Guest $guest, RemoveGuest $action): Response
    {
        $this->authorize('delete', $guest);

        $actor = request()->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->handle($guest, $actor);

        return response()->noContent();
    }
}
