<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Enums\ClaimKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexHoldsRequest;
use App\Http\Resources\Rms\HoldResource;
use App\Models\Booking;
use App\Models\CabinClaim;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\RequestQueueRules;
use DateTimeInterface;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class HoldController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\HoldResource>, meta: array{rules: array{business_day_minutes: int}}}',
    )]
    public function index(IndexHoldsRequest $request): AnonymousResourceCollection
    {
        $claims = CabinClaim::query()
            ->where('kind', ClaimKind::Hold)
            ->whereNull('released_at')
            ->with([
                'cabin',
                'departure.property',
                'holder' => function (Relation $morph): void {
                    if ($morph instanceof MorphTo) {
                        $morph->morphWith([
                            Booking::class => ['contact', 'cabin', 'departure.property', 'bookingRequest', 'claims'],
                        ]);
                    }
                },
            ])
            ->when(
                $request->filled('from') || $request->filled('to'),
                function (Builder $query) use ($request): void {
                    $query->whereHas('departure', function (Builder $departure) use ($request): void {
                        $departure
                            ->when($request->filled('from'), fn (Builder $inner) => $inner->whereDate('date', '>=', (string) $request->validated('from')))
                            ->when($request->filled('to'), fn (Builder $inner) => $inner->whereDate('date', '<=', (string) $request->validated('to')));
                    });
                },
            )
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CabinClaim $claim): string => $claim->holder_type.'|'.$claim->holder_id);

        $bookings = [];

        foreach ($claims as $group) {
            $first = $group->first();
            $holder = $first?->holder;

            if (! $holder instanceof Booking) {
                continue;
            }

            $bookings[] = $holder;
        }

        usort($bookings, function (Booking $left, Booking $right): int {
            $leftExpiry = $left->claims->first(fn ($claim): bool => $claim->released_at === null)?->expires_at;
            $rightExpiry = $right->claims->first(fn ($claim): bool => $claim->released_at === null)?->expires_at;
            $leftTs = $leftExpiry instanceof DateTimeInterface ? $leftExpiry->getTimestamp() : 0;
            $rightTs = $rightExpiry instanceof DateTimeInterface ? $rightExpiry->getTimestamp() : 0;

            return $leftTs <=> $rightTs;
        });

        return HoldResource::collection($bookings)->additional([
            'meta' => [
                'rules' => [
                    'business_day_minutes' => RequestQueueRules::businessDayMinutes(app(CurrentConfig::class)),
                ],
            ],
        ]);
    }
}
