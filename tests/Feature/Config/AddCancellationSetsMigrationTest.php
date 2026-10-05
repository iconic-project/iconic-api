<?php

declare(strict_types=1);

use App\Enums\ConfigKind;
use App\Models\Booking;
use App\Models\BusinessRuleVersion;
use App\Models\ChangeHistory;
use App\Models\RateVersion;
use App\Services\Config\ConfigPublisher;
use App\Support\Config\Documents\BusinessRulesDocument;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Validation\ValidationException;

const CANCELLATION_SETS_APPROVAL = 'Sprint 18: cancellation sets (09 H8)';

/**
 * @return array<string, mixed>
 */
function preChangeCancellationSets(): array
{
    $document = BusinessRulesDocument::initial();
    unset($document['cancellation']['sets']);

    return $document;
}

function runCancellationSetsMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_05_125001_add_cancellation_sets.php');
    $migration->up();
}

function insertPreChangeCancellationSets(): BusinessRuleVersion
{
    $row = new BusinessRuleVersion([
        'version' => 1,
        'document' => preChangeCancellationSets(),
        'changes' => [],
        'approval_reference' => 'PRE-CHANGE',
        'published_at' => now(),
        'created_by' => null,
        'updated_by' => null,
    ]);
    $row->save();

    return $row;
}

test('config-verify fails when cancellation sets are missing, then the migration publishes them', function (): void {
    $v1 = insertPreChangeCancellationSets();
    $this->seed(ConfigSeeder::class);

    $booking = Booking::factory()->create([
        'deposit_pct' => 10,
        'balance_days' => 120,
    ]);

    $this->artisan('iconic:config-verify')
        ->assertFailed()
        ->expectsOutputToContain('business_rules v1: cancellation.sets');

    runCancellationSetsMigration();

    $v1->refresh();
    expect($v1->document['cancellation'])->not->toHaveKey('sets');

    $booking->refresh();
    expect($booking->deposit_pct)->toBe(10);
    expect($booking->balance_days)->toBe(120);

    $v2 = BusinessRuleVersion::query()->orderByDesc('version')->firstOrFail();
    expect($v2->version)->toBe(2);
    expect($v2->created_by)->toBeNull();
    expect($v2->approval_reference)->toBe(CANCELLATION_SETS_APPROVAL);

    $sets = $v2->asDocument()->toArray()['cancellation']['sets'];
    $bands = $v2->asDocument()->toArray()['cancellation']['bands'];
    expect($sets['STANDARD'])->toBe($bands);
    expect($sets['CHARTER'])->toBe($v2->asDocument()->toArray()['cancellation']['charter_bands']);
    expect($sets['standard'])->toBe($bands);
    expect($sets['non_refundable'])->toBe($bands);

    $history = ChangeHistory::query()
        ->where('event', 'business_rules.published')
        ->where('subject_id', $v2->id)
        ->first();

    expect($history)->not->toBeNull();
    expect($history?->actor_label)->toBe('System');

    $this->artisan('iconic:config-verify')
        ->assertSuccessful()
        ->expectsOutputToContain('business_rules v2: valid');
});

test('a rate plan whose cancellation set does not exist is refused at publish', function (): void {
    $this->seed(ConfigSeeder::class);

    $current = RateVersion::query()->orderByDesc('version')->firstOrFail();
    $document = $current->asDocument()->toArray();
    $document['rate_plans'][0]['cancellation'] = 'NO_SUCH';

    try {
        app(ConfigPublisher::class)->publish(
            ConfigKind::Rates,
            $document,
            $current->version,
            'Sprint 18 test',
            null,
        );
        $this->fail('A missing cancellation set was published.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['document.rate_plans.0.cancellation'][0])
            ->toBe('Cancellation set NO_SUCH does not exist.');
    }
});
