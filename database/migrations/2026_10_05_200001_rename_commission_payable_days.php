<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Renames commission.payable_days_after_cruise to
 * payable_days_after_check_out. The number does not change (09 H10).
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
        $commission = $document['commission'] ?? null;

        if (! is_array($commission) || array_key_exists('payable_days_after_check_out', $commission)) {
            return;
        }

        $commission['payable_days_after_check_out'] = (int) ($commission['payable_days_after_cruise'] ?? 30);
        unset($commission['payable_days_after_cruise']);
        $document['commission'] = $commission;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 19: payable_days_after_cruise renamed payable_days_after_check_out (09 H10)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
