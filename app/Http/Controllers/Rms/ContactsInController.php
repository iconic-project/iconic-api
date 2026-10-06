<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexContactsInRequest;
use App\Http\Resources\Rms\ContactInResource;
use App\Http\Resources\Rms\ContactsInNationalitiesResource;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\User;
use App\Support\Countries;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

final class ContactsInController extends Controller
{
    public function index(IndexContactsInRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $bookings = $this->visibleInWindow($actor, $request->fromDate(), $request->toDate())
            ->withChargesSummary()
            ->with(['contact', 'owner', 'bookingRequest'])
            ->orderBy('bookings.check_in')
            ->orderBy('bookings.reference')
            ->paginate($request->integer('per_page', 50));

        return ContactInResource::collection($bookings);
    }

    #[DocumentedResponse(
        status: 200,
        type: 'array{nationalities: list<array{nationality: string, country_name: string, guests: int, bookings: int}>, unknown: int, total_guests: int}',
    )]
    public function nationalities(IndexContactsInRequest $request): ContactsInNationalitiesResource
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $visible = $this->visibleInWindow($actor, $request->fromDate(), $request->toDate())
            ->whereNotIn('bookings.status', [
                BookingStatus::Cancelled,
                BookingStatus::CancelledPostpaid,
                BookingStatus::Released,
            ])
            ->select('bookings.id');

        /** @var Collection<int, object{nationality: string|null, guests: int|string, bookings: int|string}> $rows */
        $rows = Guest::query()
            ->named()
            ->whereIn('booking_id', $visible)
            ->selectRaw("CASE WHEN nationality IS NULL OR nationality = '' THEN '' ELSE nationality END as nationality")
            ->selectRaw('COUNT(*) as guests')
            ->selectRaw('COUNT(DISTINCT booking_id) as bookings')
            ->groupByRaw("CASE WHEN nationality IS NULL OR nationality = '' THEN '' ELSE nationality END")
            ->get();

        $unknown = 0;
        $total = 0;
        $known = [];

        foreach ($rows as $row) {
            $guests = (int) $row->guests;
            $total += $guests;
            $code = (string) $row->nationality;

            if ($code === '') {
                $unknown = $guests;

                continue;
            }

            $known[] = [
                'nationality' => $code,
                'country_name' => Countries::name($code),
                'guests' => $guests,
                'bookings' => (int) $row->bookings,
            ];
        }

        usort($known, function (array $left, array $right): int {
            if ($left['guests'] !== $right['guests']) {
                return $right['guests'] <=> $left['guests'];
            }

            return $left['country_name'] <=> $right['country_name'];
        });

        return new ContactsInNationalitiesResource([
            'nationalities' => array_slice($known, 0, 10),
            'unknown' => $unknown,
            'total_guests' => $total,
        ]);
    }

    /**
     * @return Builder<Booking>
     */
    private function visibleInWindow(User $actor, ?string $from, ?string $to): Builder
    {
        return Booking::query()
            ->select('bookings.*')
            ->visibleTo($actor)
            ->departingBetween($from, $to);
    }
}
