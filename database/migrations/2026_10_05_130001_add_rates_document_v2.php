<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\RateVersion;
use App\Services\Config\ConfigPublisher;
use App\Support\Config\Documents\Rates\RatesV2;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Republishes rates when schema version 2 is missing.
 * Local and testing copy the hotel fixture. Other environments get empty
 * lists so every night is unrated until real rates are published.
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = RateVersion::query()->orderByDesc('version')->first();

        if (! $current instanceof RateVersion) {
            return;
        }

        $document = $current->document;

        if ((int) ($document['schema_version'] ?? 0) === RatesV2::SCHEMA_VERSION && array_key_exists('seasons', $document)) {
            return;
        }

        foreach (RatesV2::keys() as $key => $value) {
            $document[$key] = $value;
        }

        app(ConfigPublisher::class)->publish(
            ConfigKind::Rates,
            $document,
            $current->version,
            RatesV2::APPROVAL_REFERENCE,
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
