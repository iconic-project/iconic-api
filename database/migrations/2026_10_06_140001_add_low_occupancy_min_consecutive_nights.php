<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Adds alerts.low_occupancy_min_consecutive_nights.
 * The fixture names no number. 1 keeps a short run (09 H15).
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = BusinessRuleVersion::query()->orderByDesc('version')->first();

        if (! $current instanceof BusinessRuleVersion) {
            return;
        }

        $document = $current->document;
        $alerts = is_array($document['alerts'] ?? null) ? $document['alerts'] : [];

        if (array_key_exists('low_occupancy_min_consecutive_nights', $alerts)) {
            return;
        }

        $alerts['low_occupancy_min_consecutive_nights'] = 1;
        $document['alerts'] = $alerts;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 21: low_occupancy_min_consecutive_nights added (default 1, source 09 H15)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
