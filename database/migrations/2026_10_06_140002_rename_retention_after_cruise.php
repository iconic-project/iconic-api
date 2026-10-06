<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Renames retention passport and medical keys to check-out.
 * The numbers do not change (09 H10).
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
        $retention = is_array($document['retention'] ?? null) ? $document['retention'] : [];
        $passportDone = array_key_exists('passport_months_after_check_out', $retention)
            && ! array_key_exists('passport_months_after_cruise', $retention);
        $medicalDone = array_key_exists('medical_days_after_check_out', $retention)
            && ! array_key_exists('medical_days_after_cruise', $retention);

        if ($passportDone && $medicalDone) {
            return;
        }

        if (! array_key_exists('passport_months_after_check_out', $retention)) {
            $retention['passport_months_after_check_out'] = (int) ($retention['passport_months_after_cruise'] ?? 24);
        }

        if (! array_key_exists('medical_days_after_check_out', $retention)) {
            $retention['medical_days_after_check_out'] = (int) ($retention['medical_days_after_cruise'] ?? 90);
        }

        unset($retention['passport_months_after_cruise'], $retention['medical_days_after_cruise']);
        $document['retention'] = $retention;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 21: passport_months_after_cruise and medical_days_after_cruise renamed (09 H10)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
