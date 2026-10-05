<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use App\Support\Config\Documents\Taxes;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Publishes business-rules `taxes`. Local and testing copy the
 * hotel fixture (currently no rows). Other environments get an empty list.
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

        if (array_key_exists('taxes', $document)) {
            return;
        }

        $document['taxes'] = Taxes::list();

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            Taxes::APPROVAL_REFERENCE,
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
