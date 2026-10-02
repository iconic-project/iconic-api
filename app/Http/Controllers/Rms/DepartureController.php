<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Departures\CreateDeparture;
use App\Actions\Departures\DeleteDeparture;
use App\Actions\Departures\GenerateSeason;
use App\Actions\Departures\UpdateDeparture;
use App\Enums\DepartureStatus;
use App\Enums\SeasonPattern;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\GenerateSeasonRequest;
use App\Http\Requests\Rms\IndexDeparturesRequest;
use App\Http\Requests\Rms\StoreDepartureRequest;
use App\Http\Requests\Rms\UpdateDepartureRequest;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\DepartureMutationResource;
use App\Http\Resources\Rms\DepartureResource;
use App\Http\Resources\Rms\GenerateSeasonResource;
use App\Models\Departure;
use App\Models\Property;
use App\Services\Inventory\Availability;
use App\Support\Inventory\Snapshots;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

final class DepartureController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\DepartureResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string|null, per_page: int, to: int|null, total: int, kpis: array{on_sale_on_engine: int, cabins_bookable: int, showing_only_n_left: int, full: int}}}',
    )]
    public function index(IndexDeparturesRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Departure::class);

        $perPage = $request->integer('per_page', 100);
        $page = $request->integer('page', 1);

        $query = Departure::query()
            ->with(['property', 'itinerary'])
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('date', '>=', (string) $request->validated('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('date', '<=', (string) $request->validated('to')))
            ->when($request->filled('property_id'), fn (Builder $query) => $query->where('property_id', $request->validated('property_id')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->validated('status')))
            ->orderBy('date')
            ->orderBy(
                Property::query()->select('code')->whereColumn('properties.id', 'departures.property_id'),
            );

        $all = $query->get();
        $snapshots = Snapshots::attach($all);
        $kpis = app(Availability::class)->kpis($all, $snapshots);

        $paginator = new LengthAwarePaginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return DepartureResource::collection($paginator)->additional([
            'meta' => $this->indexMeta($kpis),
        ]);
    }

    public function show(Departure $departure): DepartureResource
    {
        $this->authorize('view', $departure);

        Snapshots::attach(collect([$departure]));

        return new DepartureResource($departure);
    }

    public function layout(Departure $departure): DepartureResource
    {
        $this->authorize('view', $departure);

        Snapshots::attach(collect([$departure]));

        return new DepartureResource($departure);
    }

    public function store(StoreDepartureRequest $request, CreateDeparture $action): JsonResponse
    {
        $this->authorize('create', Departure::class);

        $departure = $action->handle($request->validated());
        Snapshots::attach(collect([$departure]));

        return (new DepartureMutationResource($departure))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDepartureRequest $request, Departure $departure, UpdateDeparture $action): DepartureMutationResource
    {
        $this->authorize('update', $departure);

        $updated = $action->handle($departure, $request->validated());
        Snapshots::attach(collect([$updated]));

        return new DepartureMutationResource($updated);
    }

    public function destroy(Departure $departure, DeleteDeparture $action): Response
    {
        $this->authorize('delete', $departure);

        $action->handle($departure);

        return response()->noContent();
    }

    public function generate(GenerateSeasonRequest $request, GenerateSeason $action): GenerateSeasonResource
    {
        $this->authorize('generate', Departure::class);

        $validated = $request->validated();

        $result = $action->handle(
            CreateDeparture::calendarDate($validated['from']),
            CreateDeparture::calendarDate($validated['to']),
            array_map(intval(...), $validated['property_ids']),
            SeasonPattern::from((string) $validated['pattern']),
            (bool) $validated['festive_window'],
            DepartureStatus::from((string) $validated['status']),
        );

        return new GenerateSeasonResource($result);
    }

    public function history(Departure $departure): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $departure);

        $entries = $departure->history()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ChangeHistoryResource::collection($entries);
    }

    /**
     * @param  array{on_sale_on_engine: int, cabins_bookable: int, showing_only_n_left: int, full: int}  $kpis
     * @return array{kpis: array{on_sale_on_engine: int, cabins_bookable: int, showing_only_n_left: int, full: int}}
     */
    private function indexMeta(array $kpis): array
    {
        return ['kpis' => $kpis];
    }
}
