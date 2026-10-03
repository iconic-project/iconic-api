<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only — no Schema:: calls. The feature test re-runs up() inside RefreshDatabase.
 *
 * The defaults are hard-coded so this stays reproducible if BusinessRulesDocument::initial() changes.
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
        $stay = is_array($document['stay'] ?? null) ? $document['stay'] : [];
        $keys = [
            'check_in_time',
            'check_out_time',
            'no_show_cutoff_time',
            'min_nights',
            'max_nights',
            'max_rooms_per_booking',
            'check_in_requires_full_payment',
            'booking_horizon_days',
        ];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $stay)) {
                $document['stay'] = [
                    // TODO(OPEN: HQ3) demo value
                    'check_in_time' => '15:00',
                    'check_out_time' => '11:00',
                    'no_show_cutoff_time' => '23:59',
                    'min_nights' => 1,
                    'max_nights' => 30,
                    'max_rooms_per_booking' => 5,
                    'check_in_requires_full_payment' => true,
                    'booking_horizon_days' => 730,
                ];

                app(ConfigPublisher::class)->publish(
                    ConfigKind::BusinessRules,
                    $document,
                    $current->version,
                    'Sprint 16: stay added (defaults demo, source 09 H3)',
                    null,
                );

                return;
            }
        }
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
