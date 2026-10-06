<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Adds reports.pickup_days.
 * The task names N and no fixture states a number. 7 is the open default.
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
        $reports = is_array($document['reports'] ?? null) ? $document['reports'] : [];

        if (array_key_exists('pickup_days', $reports)) {
            return;
        }

        $reports['pickup_days'] = 7;
        $document['reports'] = $reports;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 21: pickup_days added (default 7, source 21-06, OPEN)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
