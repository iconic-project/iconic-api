<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Enums\DocumentPlanKind;
use App\Enums\DocumentPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexClientDocumentsRequest;
use App\Http\Resources\Rms\DocumentPlanRowResource;
use App\Models\Booking;
use App\Models\User;
use App\Support\Documents\DocumentPlan;
use App\Support\Documents\DocumentPlanRow;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class ClientDocumentController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\DocumentPlanRowResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string|null, per_page: int, to: int|null, total: int, filters: array{kinds: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>}}}',
    )]
    public function index(IndexClientDocumentsRequest $request, DocumentPlan $plan): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $perPage = $request->integer('per_page', 50);
        $page = max(1, $request->integer('page', 1));
        $kind = $request->filled('kind')
            ? DocumentPlanKind::from((string) $request->validated('kind'))
            : null;
        $status = $request->filled('status')
            ? DocumentPlanStatus::from((string) $request->validated('status'))
            : null;
        $search = $request->validated('q');

        $bookings = Booking::query()
            ->select('bookings.*')
            ->with([
                'contact',
                'group.coordinator',
                'agency',
                'guests',
                'extras',
                'payments',
                'documents.deliveries',
                'deliveries',
            ])
            ->visibleTo($actor)
            ->departingBetween(
                is_string($request->validated('from')) ? $request->validated('from') : null,
                is_string($request->validated('to')) ? $request->validated('to') : null,
            )
            ->when(
                is_string($search) && $search !== '',
                function ($query) use ($search): void {
                    $query->where(function ($inner) use ($search): void {
                        $inner->where('bookings.reference', 'like', '%'.$search.'%')
                            ->orWhere('bookings.request_reference', 'like', '%'.$search.'%')
                            ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', '%'.$search.'%'));
                    });
                },
            )
            ->orderBy('bookings.check_in')
            ->orderBy('bookings.id')
            ->get();

        $rows = $bookings
            ->flatMap(fn (Booking $booking): array => $plan->for($booking, $actor))
            ->when(
                $kind instanceof DocumentPlanKind,
                fn ($collection) => $collection->filter(
                    fn (DocumentPlanRow $row): bool => $row->kind === $kind,
                ),
            )
            ->when(
                $status instanceof DocumentPlanStatus,
                fn ($collection) => $collection->filter(
                    fn (DocumentPlanRow $row): bool => $row->status === $status,
                ),
            )
            ->values();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return DocumentPlanRowResource::collection($paginator)->additional([
            'meta' => [
                'filters' => [
                    'kinds' => array_map(
                        fn (DocumentPlanKind $kind): array => [
                            'value' => $kind->value,
                            'label' => $kind->label(),
                        ],
                        DocumentPlanKind::cases(),
                    ),
                    'statuses' => array_map(
                        fn (DocumentPlanStatus $status): array => [
                            'value' => $status->value,
                            'label' => $status->label(),
                        ],
                        DocumentPlanStatus::cases(),
                    ),
                ],
            ],
        ]);
    }
}
