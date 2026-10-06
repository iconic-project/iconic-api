<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Models\EngineSettingsVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Drops the exclusive-use enquiry groups from the current
 * business-rules and engine-settings documents (HQ1, Sprint 22).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->strip(ConfigKind::BusinessRules, BusinessRuleVersion::class);
        $this->strip(ConfigKind::EngineSettings, EngineSettingsVersion::class);
    }

    public function down(): void
    {
        // Versions are append-only.
    }

    /**
     * @param  class-string<Model>  $versionClass
     */
    private function strip(ConfigKind $kind, string $versionClass): void
    {
        $current = $versionClass::query()->orderByDesc('version')->first();

        if (! $current instanceof Model) {
            return;
        }

        $document = $current->getAttribute('document');

        if (! is_array($document) || ! array_key_exists('charter', $document)) {
            return;
        }

        unset($document['charter']);

        app(ConfigPublisher::class)->publish(
            $kind,
            $document,
            (int) $current->getAttribute('version'),
            'Sprint 22: charter keys removed (exclusive use dropped, HQ1)',
            null,
        );
    }
};
