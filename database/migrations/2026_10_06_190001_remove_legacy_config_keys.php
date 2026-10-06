<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Models\EngineSettingsVersion;
use App\Models\RateVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Drops yacht rate, engine and manifest keys from the current
 * documents (09 H19). One publish per document that still holds one.
 */
return new class extends Migration
{
    private const APPROVAL = 'Sprint 22: legacy keys removed (09 H19)';

    /** @var list<string> */
    private const RATE_RULES = [
        'single_supplement_pct',
        'triple_discount_pct',
        'child_discount_pct',
        'child_discounts_per_adult',
        'child_discounts_per_cabin',
        'festive_supplement_pp',
        'festive_supplement_charter',
    ];

    public function up(): void
    {
        $this->strip(ConfigKind::Rates, RateVersion::class);
        $this->strip(ConfigKind::EngineSettings, EngineSettingsVersion::class);
        $this->strip(ConfigKind::BusinessRules, BusinessRuleVersion::class);
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

        if (! is_array($document) || ! $this->dirty($kind, $document)) {
            return;
        }

        app(ConfigPublisher::class)->publish(
            $kind,
            $this->clean($kind, $document),
            (int) $current->getAttribute('version'),
            self::APPROVAL,
            null,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function dirty(ConfigKind $kind, array $document): bool
    {
        return match ($kind) {
            ConfigKind::Rates => $this->ratesDirty($document),
            ConfigKind::EngineSettings => $this->engineDirty($document),
            ConfigKind::BusinessRules => array_key_exists('manifests', $document) || array_key_exists('charter', $document),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function clean(ConfigKind $kind, array $document): array
    {
        return match ($kind) {
            ConfigKind::Rates => $this->cleanRates($document),
            ConfigKind::EngineSettings => $this->cleanEngine($document),
            ConfigKind::BusinessRules => $this->cleanRules($document),
            default => $document,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function ratesDirty(array $document): bool
    {
        if (array_key_exists('years', $document)) {
            return true;
        }

        $rules = $document['rules'] ?? null;

        if (is_array($rules)) {
            foreach (self::RATE_RULES as $key) {
                if (array_key_exists($key, $rules)) {
                    return true;
                }
            }
        }

        $terms = $document['terms'] ?? null;

        if (! is_array($terms)) {
            return false;
        }

        foreach (array_keys($terms) as $key) {
            if (is_string($key) && (str_starts_with($key, 'cabin_') || str_starts_with($key, 'charter_'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function engineDirty(array $document): bool
    {
        if (array_key_exists('charter', $document)) {
            return true;
        }

        $fees = $document['fees'] ?? null;

        if (is_array($fees) && (array_key_exists('png', $fees) || array_key_exists('tct_pp', $fees))) {
            return true;
        }

        $guests = $document['guests'] ?? null;

        return is_array($guests) && (array_key_exists('max_per_cabin', $guests) || array_key_exists('max_per_yacht', $guests));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function cleanRates(array $document): array
    {
        unset($document['years']);

        if (is_array($document['rules'] ?? null)) {
            foreach (self::RATE_RULES as $key) {
                unset($document['rules'][$key]);
            }

            if ($document['rules'] === []) {
                unset($document['rules']);
            }
        }

        if (is_array($document['terms'] ?? null)) {
            foreach (array_keys($document['terms']) as $key) {
                if (is_string($key) && (str_starts_with($key, 'cabin_') || str_starts_with($key, 'charter_'))) {
                    unset($document['terms'][$key]);
                }
            }

            if ($document['terms'] === []) {
                unset($document['terms']);
            }
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function cleanEngine(array $document): array
    {
        unset($document['charter']);

        if (is_array($document['fees'] ?? null)) {
            unset($document['fees']['png'], $document['fees']['tct_pp']);
        }

        if (is_array($document['guests'] ?? null)) {
            unset($document['guests']['max_per_cabin'], $document['guests']['max_per_yacht']);
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function cleanRules(array $document): array
    {
        unset($document['manifests'], $document['charter']);

        return $document;
    }
};
