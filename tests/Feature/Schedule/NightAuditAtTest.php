<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\BusinessRuleVersion;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Schedule\IconicSchedule;

test('night audit is one minute after the published cutoff', function (): void {
    $document = BusinessRulesDocument::initial();
    $document['stay']['no_show_cutoff_time'] = '22:15';
    publishBusinessRules($document);

    expect(IconicSchedule::nightAuditAt())->toBe('22:16');
});

test('night audit uses the demo cutoff when the published time is blank', function (): void {
    $document = BusinessRulesDocument::initial();
    $document['stay']['no_show_cutoff_time'] = '';
    publishBusinessRules($document);

    expect(IconicSchedule::nightAuditAt())->toBe('00:00');
});

test('night audit uses the demo cutoff when stay is absent from the published document', function (): void {
    $document = BusinessRulesDocument::initial();
    unset($document['stay']);
    publishBusinessRules($document);

    expect(IconicSchedule::nightAuditAt())->toBe('00:00');
});

/**
 * @param  array<string, mixed>  $document
 */
function publishBusinessRules(array $document): void
{
    (new BusinessRuleVersion([
        'version' => 1,
        'document' => $document,
        'changes' => [],
        'approval_reference' => 'NIGHT-AUDIT',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]))->save();

    app(CurrentConfig::class)->forget(ConfigKind::BusinessRules);
}
