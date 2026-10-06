<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Extras\AddBookingExtra;
use App\Actions\Extras\RemoveBookingExtra;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\AddBookingExtraRequest;
use App\Http\Resources\Rms\BookingExtraResource;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Guests\GuestIssues;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class BookingExtraController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\BookingExtraResource>, extras_total: int, tct_collected: bool, png_known_total: int, png_pending_count: int, tct_pp: int, tct_count: int, extras_due_hours: int, extras_due_at: string}',
    )]
    public function index(Booking $booking, GuestIssues $issues, CurrentConfig $config): AnonymousResourceCollection
    {
        $this->authorize('view', $booking);

        $booking->load(['extras', 'guests']);
        $summary = $issues->summary($booking);
        $tctPp = (int) ($booking->tct_rate_usd ?? 0);

        return BookingExtraResource::collection($booking->extras)
            ->additional([
                'extras_total' => $booking->extrasTotal(),
                'tct_collected' => $booking->tct_collected,
                'png_known_total' => $summary['png_known_total'],
                'png_pending_count' => $summary['png_pending_count'],
                'tct_pp' => $tctPp,
                'tct_count' => $summary['total'],
                'extras_due_hours' => $config->businessRules()->payments->extrasDueHours,
                'extras_due_at' => Iso::utc($booking->extrasDueAt()),
            ]);
    }

    public function store(AddBookingExtraRequest $request, Booking $booking, AddBookingExtra $action): JsonResponse
    {
        $this->authorize('updateExtras', $booking);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $extra = $action->handle($booking, $request->validated(), $actor);

        return (new BookingExtraResource($extra))->response()->setStatusCode(201);
    }

    public function destroy(BookingExtra $extra, RemoveBookingExtra $action): Response
    {
        $this->authorize('delete', $extra);

        $actor = request()->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->handle($extra, $actor);

        return response()->noContent();
    }
}
