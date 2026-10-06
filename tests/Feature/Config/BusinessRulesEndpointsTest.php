<?php

declare(strict_types=1);

use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a manager and a sales exec cannot view or publish business rules', function (): void {
    $document = businessRulesDocument();
    $document['commission']['cap_pct'] = 15;

    foreach ([managerUser(['name' => 'Mateo R.']), salesExecUser(['name' => 'Lucía B.'])] as $user) {
        $this->actingAs($user)
            ->getJson('/api/rms/business-rules')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/rms/business-rules/versions', [
                'document' => $document,
                'base_version' => 1,
                'approval_reference' => 'BOARD-1',
            ])
            ->assertForbidden();
    }

    expect(BusinessRuleVersion::query()->count())->toBe(1);
});

test('an admin can view the document, registry and counts after a fresh seed', function (): void {
    $response = $this->actingAs(adminUser(['name' => 'Carolina M.']))
        ->getJson('/api/rms/business-rules')
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('document.commission.cap_pct', 12)
        ->assertJsonPath('published_by', null);

    expect($response->json('registry'))->toHaveCount(102);
    expect($response->json('counts'))->toBe([
        'all' => 102,
        'here' => 77,
        'other_pages' => 15,
        'locked' => 10,
        'differs_or_flagged' => 56,
    ]);

    $confirmedHere = collect($response->json('registry'))
        ->where('where', 'here')
        ->where('status', 'CONFIRMED');

    expect($confirmedHere->every(fn (array $row): bool => $row['differs'] === false))->toBeTrue();
});

test('an admin can publish a commission cap change and the registry row differs', function (): void {
    $admin = adminUser(['name' => 'Carolina M.']);
    $document = businessRulesDocument();
    $document['commission']['cap_pct'] = 15;

    $this->actingAs($admin)
        ->postJson('/api/rms/business-rules/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-22',
        ])
        ->assertCreated()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('changes.0.path', 'commission.cap_pct')
        ->assertJsonPath('changes.0.label', 'FIN-005 · Max agency commission')
        ->assertJsonPath('changes.0.from', 12)
        ->assertJsonPath('changes.0.to', 15)
        ->assertJsonPath('approval_reference', 'BOARD-22');

    $entry = ChangeHistory::query()->where('event', 'business_rules.published')->latest('id')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->reason)->toBe('BOARD-22');
    expect($entry?->subject_type)->toBe('business_rule_version');

    $this->actingAs($admin)
        ->getJson('/api/rms/business-rules')
        ->assertOk()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('document.commission.cap_pct', 15);

    $row = collect($this->actingAs($admin)->getJson('/api/rms/business-rules')->json('registry'))
        ->firstWhere('key', 'fin-005-commission-cap');

    expect($row['differs'])->toBeTrue();
    expect($row['current_display'])->toBe('15%');
});
