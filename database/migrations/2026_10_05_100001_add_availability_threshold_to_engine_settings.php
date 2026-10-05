<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\EngineSettingsVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DML only. Republishes engine settings when availability.low_availability_threshold is missing.
 * The default is the most common departures.urgency_threshold, or 3 when the table is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = EngineSettingsVersion::query()->orderByDesc('version')->first();

        if (! $current instanceof EngineSettingsVersion) {
            return;
        }

        $document = $current->document;
        $availability = is_array($document['availability'] ?? null) ? $document['availability'] : [];

        if (array_key_exists('low_availability_threshold', $availability)) {
            return;
        }

        $availability['low_availability_threshold'] = $this->threshold();
        $document['availability'] = $availability;

        app(ConfigPublisher::class)->publish(
            ConfigKind::EngineSettings,
            $document,
            $current->version,
            'Sprint 17: availability.low_availability_threshold added (09 H7)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }

    private function threshold(): int
    {
        $row = DB::table('departures')
            ->select('urgency_threshold')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('urgency_threshold')
            ->orderByDesc('aggregate')
            ->orderBy('urgency_threshold')
            ->first();

        if ($row === null) {
            return 3;
        }

        return (int) $row->urgency_threshold;
    }
};
