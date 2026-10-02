<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only — no Schema:: calls. The feature test re-runs up() inside RefreshDatabase.
 *
 * Defaults are hard-coded so this stays reproducible if BusinessRulesDocument::initial() changes.
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
        $entity = is_array($document['legal_entity'] ?? null) ? $document['legal_entity'] : [];
        $bank = is_array($entity['bank'] ?? null) ? $entity['bank'] : [];

        $defaults = [
            'name' => 'PONTOS LLC (a limited liability company)',
            'address_lines' => [
                '430 Grand Bay Drive, Apt 1108',
                'Key Biscayne, FL 33149, United States',
            ],
            'email' => 'info@iconic.co',
            'website' => 'iconic.co',
            'ein' => '42-4742064',
        ];

        $bankDefaults = [
            'bank_name' => '[TBD]',
            'account_name' => '[TBD]',
            'account_number' => '[TBD]',
            'routing' => '[TBD]',
            'swift' => '[TBD]',
        ];

        $added = false;

        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $entity)) {
                $entity[$key] = $value;
                $added = true;
            }
        }

        foreach ($bankDefaults as $key => $value) {
            if (! array_key_exists($key, $bank)) {
                $bank[$key] = $value;
                $added = true;
            }
        }

        if (! $added) {
            return;
        }

        $entity['bank'] = $bank;
        $document['legal_entity'] = $entity;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            'Sprint 7: legal_entity added (defaults from decision row 8; bank [TBD], source LEG-004 pending client)',
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
