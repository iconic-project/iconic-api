<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\AlertNotificationStatus;
use App\Enums\Permission;
use App\Enums\ReportRunStatus;
use App\Mail\Reports\ReportMail;
use App\Models\Alert;
use App\Models\ReportRun;
use App\Models\ReportRunNotification;
use App\Models\ReportSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Metrics\CommercialMetrics;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Reports\ReportQueries;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
    ScheduleFail::$on = false;
});

test('each cadence fires at its Galápagos moment, catches up once, and does not repeat', function (): void {
    Mail::fake();
    $reader = reportReader();

    $daily = onlyReport('commercial-summary');
    atGalapagos('2027-11-08 07:59:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->count())->toBe(0);

    atGalapagos('2027-11-08 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $run = ReportRun::query()->first();
    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe(ReportRunStatus::Ready)
        ->and($run?->window_from->toDateString())->toBe('2027-11-07')
        ->and($run?->window_to->toDateString())->toBe('2027-11-07')
        ->and($run?->parameters['source'])->toBe('schedule')
        ->and($run?->requested_by)->toBeNull()
        ->and($run?->subscription_id)->toBe($daily->id);
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($reader->email));

    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'commercial-summary')->count())->toBe(1);
    Mail::assertSentCount(1);

    atGalapagos('2027-11-09 07:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'commercial-summary')->count())->toBe(1);
    atGalapagos('2027-11-09 10:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'commercial-summary')->count())->toBe(2);

    ReportSubscription::query()->update(['active' => false]);
    onlyReport('occupancy');
    atGalapagos('2027-11-07 09:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'occupancy')->count())->toBe(0);
    atGalapagos('2027-11-08 08:59:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'occupancy')->count())->toBe(0);
    atGalapagos('2027-11-08 09:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $weekly = ReportRun::query()->where('definition_key', 'occupancy')->first();
    expect($weekly?->window_from->toDateString())->toBe('2027-11-01')
        ->and($weekly?->window_to->toDateString())->toBe('2027-11-07');

    ReportSubscription::query()->update(['active' => false]);
    onlyReport('pipeline-summary');
    atGalapagos('2027-11-01 07:59:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'pipeline-summary')->count())->toBe(0);
    atGalapagos('2027-11-01 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $monthly = ReportRun::query()->where('definition_key', 'pipeline-summary')->first();
    expect($monthly?->window_from->toDateString())->toBe('2027-10-01')
        ->and($monthly?->window_to->toDateString())->toBe('2027-10-31');
    atGalapagos('2027-11-02 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'pipeline-summary')->count())->toBe(1);

    ReportSubscription::query()->update(['active' => false]);
    onlyReport('agency-report');
    atGalapagos('2027-11-01 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'agency-report')->count())->toBe(0);
    atGalapagos('2027-10-01 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $quarter = ReportRun::query()->where('definition_key', 'agency-report')->first();
    expect($quarter?->window_from->toDateString())->toBe('2027-07-01')
        ->and($quarter?->window_to->toDateString())->toBe('2027-09-30');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'agency-report')->count())->toBe(1);
});

test('recipients follow the permission held at send time', function (): void {
    Mail::fake();
    onlyReport('commercial-summary');
    $first = reportReader(['email' => 'first-report@iconic.test']);
    atGalapagos('2027-11-08 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($first->email));

    $first->role->forceFill(['permissions' => [Permission::PanelCrm]])->save();
    $second = reportReader(['email' => 'second-report@iconic.test']);
    Mail::fake();
    atGalapagos('2027-11-09 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($second->email));
    Mail::assertNotSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($first->email));
});

test('a failed generation raises a warning that a later run resolves, and a failed send retries once', function (): void {
    onlyReport('commercial-summary');
    reportReader(['email' => 'fail-report@iconic.test']);
    ScheduleFail::$on = true;
    app()->bind(ReportQueries::class, function ($app): ReportQueries {
        return new FlakyReportQueries(
            $app->make(CommercialMetrics::class),
            $app->make(CurrentConfig::class),
        );
    });

    atGalapagos('2027-11-08 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $failed = ReportRun::query()->first();
    expect($failed?->status)->toBe(ReportRunStatus::Failed)
        ->and($failed?->error)->toBe('report sql failed');
    $alert = Alert::query()->where('kind', AlertKind::ReportFailed)->whereNull('resolved_at')->first();
    expect($alert)->not->toBeNull()
        ->and($alert?->base_key)->toBe('report:commercial-summary');

    ScheduleFail::$on = false;
    atGalapagos('2027-11-09 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect($alert?->fresh()?->resolved_at)->not->toBeNull();

    ReportSubscription::query()->update(['active' => false]);
    onlyReport('occupancy');
    $sends = 0;
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$sends) {
        $sends++;
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andThrow(new RuntimeException('mailbox down'));

        return $pending;
    });
    atGalapagos('2027-11-08 09:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    $note = ReportRunNotification::query()->whereHas(
        'run',
        fn ($query) => $query->where('definition_key', 'occupancy'),
    )->first();
    expect($sends)->toBe(1)
        ->and($note?->status)->toBe(AlertNotificationStatus::Failed)
        ->and($note?->attempts)->toBe(1);

    $this->artisan('iconic:reports-send')->assertSuccessful();
    $occupancyNote = ReportRunNotification::query()->whereHas(
        'run',
        fn ($query) => $query->where('definition_key', 'occupancy'),
    )->first();
    expect($sends)->toBe(2)
        ->and($occupancyNote?->attempts)->toBe(2)
        ->and($occupancyNote?->status)->toBe(AlertNotificationStatus::Failed);

    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect($sends)->toBe(2)
        ->and(ReportRun::query()->where('definition_key', 'occupancy')->count())->toBe(1);
});

test('subscriptions are listed to panel.rms, changed with rules.manage, and run-now is manual', function (): void {
    Mail::fake();
    $desk = reportReader();
    $this->actingAs($desk)->getJson('/api/rms/reports/subscriptions')->assertOk()->assertJsonCount(4, 'data');
    $commercial = ReportSubscription::query()->where('definition_key', 'commercial-summary')->firstOrFail();
    $this->actingAs($desk)
        ->patchJson('/api/rms/reports/subscriptions/'.$commercial->id, ['send_at' => '07:30'])
        ->assertForbidden();
    $this->actingAs($desk)
        ->postJson('/api/rms/reports/subscriptions/'.$commercial->id.'/run-now')
        ->assertForbidden();

    $admin = adminUser();
    $this->actingAs($admin)
        ->patchJson('/api/rms/reports/subscriptions/'.$commercial->id, [
            'send_at' => '07:30',
            'active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.send_at', '07:30')
        ->assertJsonPath('data.active', false);

    expect($commercial->fresh()?->active)->toBeFalse();

    onlyReport('pipeline-summary');
    atGalapagos('2027-11-01 07:00:00');
    $created = $this->actingAs($admin)
        ->postJson('/api/rms/reports/subscriptions/'.ReportSubscription::query()->where('definition_key', 'pipeline-summary')->value('id').'/run-now')
        ->assertCreated();
    expect($created->json('data.parameters.source'))->toBe('manual')
        ->and($created->json('data.parameters.period'))->toBe('2027-11')
        ->and($created->json('data.requested_by'))->toBe($admin->id)
        ->and($created->json('data.status'))->toBe('READY');
    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo($admin->email));

    atGalapagos('2027-11-01 08:00:00');
    $this->artisan('iconic:reports-send')->assertSuccessful();
    expect(ReportRun::query()->where('definition_key', 'pipeline-summary')->count())->toBe(1);
    Mail::assertSentCount(2);
});

function onlyReport(string $key): ReportSubscription
{
    ReportSubscription::query()->where('definition_key', '!=', $key)->update(['active' => false]);
    $subscription = ReportSubscription::query()->where('definition_key', $key)->firstOrFail();
    $subscription->forceFill(['active' => true])->save();

    return $subscription->fresh() ?? $subscription;
}

function atGalapagos(string $moment): void
{
    Carbon::setTestNow(CarbonImmutable::parse($moment, BusinessTime::zone()));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reportReader(array $attributes = []): User
{
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::AgenciesManage],
    ]);

    return User::factory()->create([
        'role_id' => $role->id,
        ...$attributes,
    ]);
}

final class ScheduleFail
{
    public static bool $on = false;
}

final class FlakyReportQueries extends ReportQueries
{
    public function table(string $key, MetricWindow $window, MetricScope $scope, ?User $actor): array
    {
        if (ScheduleFail::$on) {
            throw new RuntimeException('report sql failed');
        }

        return parent::table($key, $window, $scope, $actor);
    }
}
