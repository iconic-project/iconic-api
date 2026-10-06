<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Renames nps.survey_hours_after_return to
 * survey_hours_after_check_out. The number does not change (09 H10).
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
        $nps = is_array($document['nps'] ?? null) ? $document['nps'] : [];
        $hasNew = array_key_exists('survey_hours_after_check_out', $nps);
        $hasOld = array_key_exists('survey_hours_after_return', $nps);

        if ($hasNew && ! $hasOld) {
            return;
        }

        if (! $hasNew) {
            $nps['survey_hours_after_check_out'] = (int) ($nps['survey_hours_after_return'] ?? 24);
        }

        unset($nps['survey_hours_after_return']);
        $document['nps'] = $nps;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 21: survey_hours_after_return renamed (09 H10)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
