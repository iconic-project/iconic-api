<?php

declare(strict_types=1);

use App\Models\CharterEnquiry;

test('retired charter routes answer 410 and store nothing', function (): void {
    $this->postJson('/api/engine/charter-enquiries', [
        'guests' => 8,
        'contact' => ['email' => 'gone@iconic.test'],
    ])->assertStatus(410);

    $this->getJson('/api/engine/charter-proposal/token')->assertStatus(410);
    $this->postJson('/api/engine/charter-proposal/token/accept', [])->assertStatus(410);
    $this->postJson('/api/engine/charter-proposal/token/decline', [])->assertStatus(410);

    $this->getJson('/api/rms/charter-enquiries')->assertStatus(410);
    $this->patchJson('/api/rms/charter-enquiries/1', [])->assertStatus(410);
    $this->postJson('/api/rms/charter-enquiries/1/proposal', [])->assertStatus(410);

    expect(CharterEnquiry::query()->count())->toBe(0);
});
