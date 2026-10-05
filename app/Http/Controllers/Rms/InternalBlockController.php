<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Blocks\CreateInternalBlock;
use App\Actions\Blocks\ReleaseInternalBlock;
use App\Actions\Blocks\ShortenInternalBlock;
use App\Actions\Blocks\UpdateInternalBlockNotes;
use App\Exceptions\CabinUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexInternalBlocksRequest;
use App\Http\Requests\Rms\ReleaseInternalBlockRequest;
use App\Http\Requests\Rms\ShortenInternalBlockRequest;
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
            ->with(['property', 'createdBy', 'releasedBy', 'claims.room'])
            ->when($status === 'active', fn (Builder $query) => $query->whereNull('released_at'))
            ->when($status === 'released', fn (Builder $query) => $query->whereNotNull('released_at'))
            ->when($request->filled('property_id'), fn (Builder $query) => $query->where('property_id', $request->integer('property_id')))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('ends_on', '>', (string) $request->validated('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('starts_on', '<=', (string) $request->validated('to')))
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
        $block->load(['property', 'createdBy', 'releasedBy', 'claims.room']);

        return (new InternalBlockResource($block))->response()->setStatusCode(201);
    }

    public function update(
        UpdateInternalBlockRequest $request,
        InternalBlock $block,
        UpdateInternalBlockNotes $action,
    ): InternalBlockResource {
        $this->authorize('update', $block);

        $updated = $action->handle($block, $request->validated());
        $updated->load(['property', 'createdBy', 'releasedBy', 'claims.room']);

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

        $released = $action->handle($block, (string) $request->validated('note'), $actor);
        $released->load(['property', 'createdBy', 'releasedBy', 'claims.room']);

        return new InternalBlockResource($released);
    }

    public function shorten(
        ShortenInternalBlockRequest $request,
        InternalBlock $block,
        ShortenInternalBlock $action,
    ): InternalBlockResource {
        $this->authorize('shorten', $block);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $shortened = $action->handle(
            $block,
            (string) $request->validated('starts_on'),
            (string) $request->validated('ends_on'),
            (string) $request->validated('reason'),
            $actor,
        );
        $shortened->load(['property', 'createdBy', 'releasedBy', 'claims.room']);

        return new InternalBlockResource($shortened);
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
