<?php

declare(strict_types=1);

use App\Models\ChangeHistory;
use App\Models\EngineSettingsVersion;
use App\Support\Config\Documents\EngineSettingsDocument;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('get current engine settings is version 1 with the fin-004 fee table and copy paths', function (): void {
    $this->actingAs(adminUser())
        ->getJson('/api/rms/engine-settings')
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('document.fees.png.foreign_over_12', 200)
        ->assertJsonPath('document.fees.png.foreign_12_and_under', 100)
        ->assertJsonPath('document.fees.png.can_adult', 100)
        ->assertJsonPath('document.fees.png.can_minor', 30)
        ->assertJsonPath('document.fees.png.national_or_resident', 30)
        ->assertJsonPath('document.fees.png.exempt_under_age', 2)
        ->assertJsonPath('copy_paths', EngineSettingsDocument::copyPaths())
        ->assertJsonPath('published_by', null)
        ->assertJsonPath('approval_reference', ConfigSeeder::APPROVAL_REFERENCE);
});

test('a sales exec can view engine settings and cannot publish', function (): void {
    $sales = salesExecUser(['name' => 'Lucía B.']);

    $this->actingAs($sales)
        ->getJson('/api/rms/engine-settings')
        ->assertOk()
        ->assertJsonPath('version', 1);

    $document = engineSettingsDocument();
    $document['copy']['details_note'] = 'A different details note.';

    $this->actingAs($sales)
        ->postJson('/api/rms/engine-settings/versions', [
            'document' => $document,
            'base_version' => 1,
        ])
        ->assertForbidden();

    expect(EngineSettingsVersion::query()->count())->toBe(1);
});

test('a manager can publish a copy-only change without an approval reference', function (): void {
    $manager = managerUser(['name' => 'Mateo R.']);
    $document = engineSettingsDocument();
    $document['copy']['details_note'] = 'A different details note for the engine.';

    $this->actingAs($manager)
        ->postJson('/api/rms/engine-settings/versions', [
            'document' => $document,
            'base_version' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('version', 2)
        ->assertJsonPath('changes.0.path', 'copy.details_note')
        ->assertJsonPath('approval_reference', null);

    $entry = ChangeHistory::query()->where('event', 'engine_settings.published')->latest('id')->first();
    expect($entry)->not->toBeNull();
    expect($entry?->reason)->toBeNull();
    expect($entry?->subject_type)->toBe('engine_settings_version');
});

test('a manager cannot publish a guest-rule change', function (): void {
    $manager = managerUser(['name' => 'Mateo R.']);
    $document = engineSettingsDocument();
    $document['guests']['max_per_property'] = 15;

    $this->actingAs($manager)
        ->postJson('/api/rms/engine-settings/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-1',
        ])
        ->assertForbidden()
        ->assertJsonPath(
            'message',
            'This change includes rule fields (Max guests per property). Only users who can edit engine rules can publish it.',
        );

    expect(EngineSettingsVersion::query()->count())->toBe(1);
});

test('a manager submitting a document without the guests group gets 403 not 500', function (): void {
    $manager = managerUser(['name' => 'Mateo R.']);
    $document = engineSettingsDocument();
    unset($document['guests']);

    $response = $this->actingAs($manager)
        ->postJson('/api/rms/engine-settings/versions', [
            'document' => $document,
            'base_version' => 1,
        ]);

    $response->assertForbidden();
    expect((string) $response->json('message'))->toContain(
        'Only users who can edit engine rules can publish it.',
    );

    expect(EngineSettingsVersion::query()->count())->toBe(1);
});

test('an admin submitting a document without the guests group gets 422', function (): void {
    $admin = adminUser(['name' => 'Carolina M.']);
    $document = engineSettingsDocument();
    unset($document['guests']);

    $this->actingAs($admin)
        ->postJson('/api/rms/engine-settings/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-1',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document.guests']);

    expect(EngineSettingsVersion::query()->count())->toBe(1);
});

test('an admin cannot publish a rule change without an approval reference', function (): void {
    $admin = adminUser(['name' => 'Carolina M.']);
    $document = engineSettingsDocument();
    $document['guests']['max_per_property'] = 15;

    $this->actingAs($admin)
        ->postJson('/api/rms/engine-settings/versions', [
            'document' => $document,
            'base_version' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approval_reference']);

    expect(EngineSettingsVersion::query()->count())->toBe(1);
});

test('validate reports rule_fields_changed for a guest-rule edit', function (): void {
    $admin = adminUser();
    $document = engineSettingsDocument();
    $document['guests']['max_per_property'] = 15;

    $this->actingAs($admin)
        ->postJson('/api/rms/engine-settings/validate', [
            'document' => $document,
        ])
        ->assertOk()
        ->assertJsonPath('rule_fields_changed', true)
        ->assertJsonPath('errors', []);

    $copyOnly = engineSettingsDocument();
    $copyOnly['copy']['details_note'] = 'A different details note.';

    $this->actingAs($admin)
        ->postJson('/api/rms/engine-settings/validate', [
            'document' => $copyOnly,
        ])
        ->assertOk()
        ->assertJsonPath('rule_fields_changed', false);
});
