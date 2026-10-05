<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\ConfigPublisher;
use Illuminate\Database\Migrations\Migration;

/**
 * DML only. Copies the existing cabin and charter bands into cancellation sets.
 * `standard` and `non_refundable` repeat the cabin bands so the hotel fixture
 * plan codes resolve. Non-refundable plans ignore the set and charge 100%.
 */
return new class extends Migration
{
    public const APPROVAL_REFERENCE = 'Sprint 18: cancellation sets (09 H8)';

    public function up(): void
    {
        $current = BusinessRuleVersion::query()->orderByDesc('version')->first();

        if (! $current instanceof BusinessRuleVersion) {
            return;
        }

        $document = $current->document;
        $cancellation = is_array($document['cancellation'] ?? null) ? $document['cancellation'] : [];
        $sets = is_array($cancellation['sets'] ?? null) ? $cancellation['sets'] : [];
        $required = ['STANDARD', 'CHARTER', 'standard', 'non_refundable'];
        $complete = true;

        foreach ($required as $code) {
            if (! array_key_exists($code, $sets)) {
                $complete = false;
                break;
            }
        }

        if ($complete) {
            return;
        }

        $bands = is_array($cancellation['bands'] ?? null) ? $cancellation['bands'] : [];
        $charterBands = is_array($cancellation['charter_bands'] ?? null) ? $cancellation['charter_bands'] : $bands;

        $cancellation['sets'] = [
            'STANDARD' => $bands,
            'CHARTER' => $charterBands,
            'standard' => $bands,
            'non_refundable' => $bands,
        ];
        $document['cancellation'] = $cancellation;

        app(ConfigPublisher::class)->publish(
            ConfigKind::BusinessRules,
            $document,
            $current->version,
            self::APPROVAL_REFERENCE,
            null,
        );
    }

    public function down(): void
    {
        // Versions are append-only.
    }
};
