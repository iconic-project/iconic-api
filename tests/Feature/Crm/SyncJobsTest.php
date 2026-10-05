<?php

declare(strict_types=1);

use App\Enums\ScheduledRunOutcome;
use App\Models\ScheduledRun;
use App\Support\Schedule\IconicSchedule;
use App\Support\Schedule\RecordScheduledRuns;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionProperty;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('every scheduled command is listed and has a recording hook', function (): void {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn (Event $event): string => RecordScheduledRuns::commandName($event))
        ->all();

    expect($scheduled)->not->toBeEmpty();

    foreach (app(Schedule::class)->events() as $event) {
        expect(RecordScheduledRuns::isAttached($event))->toBeTrue();
    }

    $response = $this->actingAs(salesExecUser())
        ->getJson('/api/crm/sync/jobs')
        ->assertOk();

    assertNoSensitiveFields($response);

    $listed = collect($response->json('data'))->pluck('command')->all();
    expect($listed)->toBe($scheduled);

    foreach ($response->json('data') as $row) {
        expect($row['last_started_at'])->toBeNull();
        expect($row['last_outcome'])->toBeNull();
        expect($row['next_run_at'])->not->toBeNull();
    }

    expect($response->json('meta.kpis'))->toHaveKeys(['jobs_failing', 'failures_open', 'merges_this_month']);
});

test('doc 07 jobs are catalogued and the new commands keep galapagos time and a run hook', function (): void {
    $events = collect(app(Schedule::class)->events());
    $expected = [
        'iconic:night-audit' => '0 0 * * *',
        'iconic:ledger-check' => '0 2 * * *',
        'iconic:commission-scan' => '30 2 * * *',
        'iconic:occupancy-check' => '0 7 * * *',
        'iconic:manifests-due' => '0 6 * * *',
        'iconic:document-check' => '0 * * * *',
    ];

    foreach ($expected as $command => $expression) {
        $matches = $events->filter(
            fn (Event $event): bool => RecordScheduledRuns::commandName($event) === $command,
        );

        expect($matches)->toHaveCount(1);
        $event = $matches->first();
        expect($event)->toBeInstanceOf(Event::class)
            ->and($event->expression)->toBe($expression)
            ->and($event->timezone)->toBe('Pacific/Galapagos')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue()
            ->and(RecordScheduledRuns::isAttached($event))->toBeTrue();
    }

    $catalogue = $this->actingAs(salesExecUser())->getJson('/api/crm/sync/jobs')->json('meta.catalogue');
    $byJob = collect($catalogue)->keyBy('job');

    expect($byJob->keys()->all())->toBe([
        'Ledger reconcile',
        'Commission leakage scan',
        'Hold expiry sweep',
        'Occupancy check',
        'Document version check',
        'Segment recompute',
        'Consent sweep',
    ])
        ->and($byJob['Ledger reconcile']['command'])->toBe('iconic:ledger-check')
        ->and($byJob['Ledger reconcile']['sentence'])->toBe('Drift is reported and never corrected.')
        ->and($byJob['Hold expiry sweep']['command'])->toBe('inventory:release-expired-holds')
        ->and($byJob['Segment recompute']['command'])->toBe('not needed')
        ->and($byJob['Segment recompute']['sentence'])->toBe('Not needed: segments are derived in SQL (L2).')
        ->and($byJob['Consent sweep']['command'])->toBe('not needed')
        ->and($byJob['Consent sweep']['sentence'])->toBe('Not needed: consent is read at send time from one register (M2).');
});

test('sync jobs lists every command over HTTP without the console routes loaded', function (): void {
    $schedule = app(Schedule::class);
    $events = new ReflectionProperty($schedule, 'events');
    $events->setValue($schedule, []);

    expect($schedule->events())->toBeEmpty();

    IconicSchedule::register($schedule);

    $expected = collect($schedule->events())
        ->map(fn (Event $event): string => RecordScheduledRuns::commandName($event))
        ->all();

    expect($expected)->not->toBeEmpty();

    $listed = collect($this->actingAs(salesExecUser())->getJson('/api/crm/sync/jobs')->json('data'))
        ->pluck('command')
        ->all();

    expect($listed)->toBe($expected);
});

test('a scheduled command records start, finish and outcome', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains(RecordScheduledRuns::commandName($event), 'iconic:flag-overdue'));

    expect($event)->toBeInstanceOf(Event::class);

    $event->run($this->app);

    $run = ScheduledRun::query()->where('command', 'iconic:flag-overdue')->first();
    expect($run)->not->toBeNull();
    expect($run?->outcome)->toBe(ScheduledRunOutcome::Succeeded);
    expect($run?->started_at)->not->toBeNull();
    expect($run?->finished_at)->not->toBeNull();

    $row = collect($this->actingAs(salesExecUser())->getJson('/api/crm/sync/jobs')->json('data'))
        ->firstWhere('command', 'iconic:flag-overdue');

    expect($row['last_outcome'])->toBe('succeeded');
    expect($row['last_started_at'])->not->toBeNull();
});

test('a failing scheduled command records the failure', function (): void {
    $event = app(Schedule::class)->exec('exit 1');
    RecordScheduledRuns::attach($event);
    $event->run($this->app);

    $run = ScheduledRun::query()->where('command', 'exit 1')->first();
    expect($run?->outcome)->toBe(ScheduledRunOutcome::Failed);
    expect($run?->exit_code)->not->toBe(0);
});
