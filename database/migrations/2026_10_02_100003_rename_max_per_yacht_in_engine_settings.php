<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\EngineSettingsVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Republishes engine settings when the stored document still uses
 * the retired guests key. Fresh databases publish the new key from initial().
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
        $guests = is_array($document['guests'] ?? null) ? $document['guests'] : [];

        if (! array_key_exists('max_per_yacht', $guests)) {
            return;
        }

        if (! array_key_exists('max_per_property', $guests)) {
            $guests['max_per_property'] = $guests['max_per_yacht'];
        }

        unset($guests['max_per_yacht']);
        $document['guests'] = $guests;

        $charter = is_array($document['charter'] ?? null) ? $document['charter'] : [];

        if (($charter['headline'] ?? null) === 'The yacht, entirely yours') {
            $charter['headline'] = 'The property, entirely yours';
        }

        if (is_string($charter['intro'] ?? null) && str_starts_with($charter['intro'], 'One yacht, sixteen guests')) {
            $charter['intro'] = substr_replace($charter['intro'], 'One property,', 0, strlen('One yacht,'));
        }

        $document['charter'] = $charter;

        app(ConfigPublisher::class)->publish(
            ConfigKind::EngineSettings,
            $document,
            $current->version,
            'Sprint 16: guests.max_per_property renamed from max_per_yacht (default 16, source 09 glossary)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
