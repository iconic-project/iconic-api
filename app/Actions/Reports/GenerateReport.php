<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ChannelOfOriginGroup;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Reports\ReportDefinitions;
use App\Support\Reports\ReportFiles;
use App\Support\Reports\ReportQueries;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateReport
{
    public function __construct(
        private readonly ReportQueries $queries,
        private readonly ReportFiles $files,
    ) {}

    public function handle(ReportRun $run): void
    {
        if ($run->status !== ReportRunStatus::Queued) {
            return;
        }

        try {
            $definition = ReportDefinitions::get($run->definition_key);
            $parameters = $run->parameters;
            $window = new MetricWindow($run->window_from->toDateString(), $run->window_to->toDateString());
            $scope = new MetricScope(
                propertyId: $this->intOrNull($parameters['property'] ?? null),
                channel: is_string($parameters['channel'] ?? null) && $parameters['channel'] !== ''
                    ? ChannelOfOriginGroup::from($parameters['channel'])
                    : null,
                agencyId: $this->intOrNull($parameters['agency'] ?? null),
            );
            $actor = $run->requested_by === null ? null : $run->requestedBy;
            $actor = $actor instanceof User ? $actor : null;
            $table = $this->queries->table($definition->key, $window, $scope, $actor);
            $bodies = $this->files->render($definition->title, $table, $definition->formats);
            $disk = Storage::disk('reports');
            $stem = $run->id.'/'.$definition->key;
            $paths = [];

            foreach ($bodies as $format => $body) {
                $path = $stem.'.'.$format;
                $disk->put($path, $body);
                $paths[$format] = $path;
            }

            $run->fill([
                'status' => ReportRunStatus::Ready,
                'rows' => count($table['rows']),
                'generated_at' => now(),
                'error' => null,
                'csv_path' => $paths[ReportFormat::Csv->value] ?? null,
                'xlsx_path' => $paths[ReportFormat::Xlsx->value] ?? null,
                'pdf_path' => $paths[ReportFormat::Pdf->value] ?? null,
            ])->save();
        } catch (Throwable $exception) {
            $run->fill([
                'status' => ReportRunStatus::Failed,
                'error' => $exception->getMessage(),
                'generated_at' => now(),
            ])->save();
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
