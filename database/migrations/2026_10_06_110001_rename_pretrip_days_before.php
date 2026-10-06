<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Renames documents.pretrip_days_before to
 * pre_arrival_days_before. The number does not change (09 H10).
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
        $documents = is_array($document['documents'] ?? null) ? $document['documents'] : [];
        $hasNew = array_key_exists('pre_arrival_days_before', $documents);
        $hasOld = array_key_exists('pretrip_days_before', $documents);

        if ($hasNew && ! $hasOld) {
            return;
        }

        if (! $hasNew) {
            $documents['pre_arrival_days_before'] = (int) ($documents['pretrip_days_before'] ?? 45);
        }

        unset($documents['pretrip_days_before']);
        $document['documents'] = $documents;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 21: pretrip_days_before renamed (09 H10)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
