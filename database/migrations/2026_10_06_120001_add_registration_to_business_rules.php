<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use App\Support\Config\Documents\RegistrationRules;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Adds business rules registration (09 H15, HQ9).
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

        if (is_array($document['registration'] ?? null)) {
            return;
        }

        $document['registration'] = RegistrationRules::defaults();

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 21: registration added (09 H15)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
