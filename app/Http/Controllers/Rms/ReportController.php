<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Reports\RecordReportDownload;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\IndexReportRunsRequest;
use App\Http\Requests\Rms\StoreReportRunRequest;
use App\Http\Resources\Rms\ReportDefinitionResource;
use App\Http\Resources\Rms\ReportRunResource;
use App\Jobs\Reports\GenerateReportJob;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Reports\ReportDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $actor = $this->actor();

        $definitions = array_map(fn ($definition): array => [
            'key' => $definition->key,
            'title' => $definition->title,
            'sentence' => $definition->sentence,
            'permission' => $definition->permission,
            'formats' => $definition->formats,
            'allowed' => $actor->hasPermission($definition->permission),
        ], ReportDefinitions::all());

        return ReportDefinitionResource::collection($definitions);
    }

    public function store(StoreReportRunRequest $request, string $key): JsonResponse
    {
        $actor = $this->actor();
        $definition = ReportDefinitions::find($key);

        if ($definition === null || ! ReportDefinitions::inCatalogue($key)) {
            abort(404);
        }

        if (! $actor->hasPermission($definition->permission)) {
            abort(403, 'You cannot run this report.');
        }

        $window = $request->window();
        $run = DB::transaction(function () use ($definition, $request, $window, $actor): ReportRun {
            return ReportRun::query()->create([
                'definition_key' => $definition->key,
                'parameters' => $request->parameters(),
                'window_from' => $window->from,
                'window_to' => $window->to,
                'requested_by' => $actor->id,
                'status' => ReportRunStatus::Queued,
                'rows' => 0,
            ]);
        });

        GenerateReportJob::dispatch($run->id);

        return (new ReportRunResource($run->fresh() ?? $run))
            ->response()
            ->setStatusCode(201);
    }

    public function runs(IndexReportRunsRequest $request): AnonymousResourceCollection
    {
        $actor = $this->actor();
        $allowed = [];

        foreach (ReportDefinitions::all() as $definition) {
            if ($actor->hasPermission($definition->permission)) {
                $allowed[] = $definition->key;
            }
        }

        $runs = ReportRun::query()
            ->whereIn('definition_key', $allowed === [] ? [''] : $allowed)
            ->when(
                is_string($request->validated('definition')),
                fn ($query) => $query->where('definition_key', $request->validated('definition')),
            )
            ->when(
                is_string($request->validated('from')),
                fn ($query) => $query->whereDate('window_from', '>=', $request->validated('from')),
            )
            ->when(
                is_string($request->validated('to')),
                fn ($query) => $query->whereDate('window_to', '<=', $request->validated('to')),
            )
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ReportRunResource::collection($runs);
    }

    public function file(ReportRun $run, string $format, RecordReportDownload $downloads): StreamedResponse
    {
        $actor = $this->actor();
        $definition = ReportDefinitions::find($run->definition_key);

        if ($definition === null || ! $actor->hasPermission($definition->permission)) {
            abort(403, 'You cannot download this report.');
        }

        $parsed = ReportFormat::tryFrom($format);

        if (! $parsed instanceof ReportFormat || $run->purged_at !== null) {
            abort(404);
        }

        $path = $run->getAttribute($parsed->column());

        if (! is_string($path) || $path === '' || ! Storage::disk('reports')->exists($path)) {
            abort(404);
        }

        $downloads->handle($run, $parsed, $actor);

        return Storage::disk('reports')->download($path, $run->definition_key.'.'.$parsed->value);
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
