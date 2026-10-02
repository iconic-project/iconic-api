<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Blocks\CreateInternalBlock;
use App\Actions\Blocks\ReleaseInternalBlock;
use App\Actions\Blocks\UpdateInternalBlockNotes;
use App\Exceptions\CabinUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexInternalBlocksRequest;
use App\Http\Requests\Rms\ReleaseInternalBlockRequest;
use App\Http\Requests\Rms\StoreInternalBlockRequest;
use App\Http\Requests\Rms\UpdateInternalBlockRequest;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Http\Resources\Rms\InternalBlockResource;
use App\Models\InternalBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InternalBlockController extends Controller
{
    public function index(IndexInternalBlocksRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InternalBlock::class);

        $status = (string) $request->input('status', 'active');

        $blocks = InternalBlock::query()
            ->with(['createdBy', 'releasedBy', 'claims.cabin', 'claims.departure.property'])
            ->when($status === 'active', fn (Builder $query) => $query->whereNull('released_at'))
            ->when($status === 'released', fn (Builder $query) => $query->whereNotNull('released_at'))
            ->when(
                $request->filled('from') || $request->filled('to') || $request->filled('property_id'),
                function (Builder $query) use ($request): void {
                    $query->whereHas('claims.departure', function (Builder $departure) use ($request): void {
                        $departure
                            ->when($request->filled('from'), fn (Builder $inner) => $inner->whereDate('date', '>=', (string) $request->validated('from')))
                            ->when($request->filled('to'), fn (Builder $inner) => $inner->whereDate('date', '<=', (string) $request->validated('to')))
                            ->when($request->filled('property_id'), fn (Builder $inner) => $inner->where('property_id', $request->validated('property_id')));
                    });
                },
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return InternalBlockResource::collection($blocks);
    }

    /**
     * @throws CabinUnavailableException
     */
    public function store(StoreInternalBlockRequest $request, CreateInternalBlock $action): JsonResponse
    {
        $this->authorize('create', InternalBlock::class);

        $block = $action->handle($request->validated());
        $block->load(['createdBy', 'releasedBy', 'claims.cabin', 'claims.departure.property']);

        return (new InternalBlockResource($block))->response()->setStatusCode(201);
    }

    public function update(
        UpdateInternalBlockRequest $request,
        InternalBlock $block,
        UpdateInternalBlockNotes $action,
    ): InternalBlockResource {
        $this->authorize('update', $block);

        $updated = $action->handle($block, $request->validated());
        $updated->load(['createdBy', 'releasedBy', 'claims.cabin', 'claims.departure.property']);

        return new InternalBlockResource($updated);
    }

    public function release(
        ReleaseInternalBlockRequest $request,
        InternalBlock $block,
        ReleaseInternalBlock $action,
    ): InternalBlockResource {
        $this->authorize('release', $block);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $note = $request->validated('note');
        $released = $action->handle($block, is_string($note) ? $note : null, $actor);
        $released->load(['createdBy', 'releasedBy', 'claims.cabin', 'claims.departure.property']);

        return new InternalBlockResource($released);
    }

    public function history(InternalBlock $block): AnonymousResourceCollection
    {
        $this->authorize('viewHistory', $block);

        $entries = $block->history()
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return ChangeHistoryResource::collection($entries);
    }
}
