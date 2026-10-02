<?php

declare(strict_types=1);

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Enums\AlertKind;
use App\Enums\AlertNotificationStatus;
use App\Enums\AlertSeverity;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\GuestResponseSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TaskKind;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Events\BookingOverdueFlagged;
use App\Events\BookingStatusChanged;
use App\Events\DeliveryOutcomeRecorded;
use App\Events\PaymentAwaitingWire;
use App\Events\PaymentSettled;
use App\Mail\Alerts\AlertMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Departure;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Payment;
use App\Models\User;
use App\Support\Alerts\AlertKeys;
use App\Support\Alerts\AlertRegistry;
use App\Support\BusinessTime;
use App\Support\Payments\WireWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', BusinessTime::zone()));
});

test('the commission cap is raised by its listener and by the sweep', function (): void {
    $heard = alertBooking(['status' => BookingStatus::OnHoldAgency, 'reference' => 'ANK-CAP-HEARD']);
    $quiet = alertBooking(['status' => BookingStatus::OnHoldAgency, 'reference' => 'ANK-CAP-QUIET']);

    BookingStatusChanged::dispatch($heard, BookingStatus::Confirmed, BookingStatus::OnHoldAgency);

    expect(Alert::query()->where('base_key', AlertKeys::cap($heard->id))->count())->toBe(1)
        ->and(Alert::query()->where('base_key', AlertKeys::cap($quiet->id))->count())->toBe(0);

    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('kind', AlertKind::CommissionCap)->whereNull('resolved_at')->count())->toBe(2);

    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('kind', AlertKind::CommissionCap)->count())->toBe(2);

    $heard->forceFill(['status' => BookingStatus::Confirmed])->save();
    BookingStatusChanged::dispatch($heard, BookingStatus::OnHoldAgency, BookingStatus::Confirmed);

    $resolved = Alert::query()->where('base_key', AlertKeys::cap($heard->id))->first();
    expect($resolved?->resolution)->toBe('the booking left ON_HOLD_AGENCY')
        ->and($resolved?->resolved_at)->not->toBeNull();

    $quiet->forceFill(['status' => BookingStatus::Confirmed])->save();
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('base_key', AlertKeys::cap($quiet->id))->first()?->resolution)
        ->toBe('the booking left ON_HOLD_AGENCY');
});

test('an overdue balance is raised by the flag listener and by the sweep', function (): void {
    $heard = overdueBooking('ANK-OVER-HEARD');
    $quiet = overdueBooking('ANK-OVER-QUIET');

    BookingOverdueFlagged::dispatch($heard);

    expect(Alert::query()->where('base_key', AlertKeys::overdue($heard->id, '2026-09-01'))->whereNull('resolved_at')->count())->toBe(1);

    Artisan::call('iconic:flag-overdue');

    expect(Alert::query()->where('base_key', AlertKeys::overdue($quiet->id, '2026-09-01'))->count())->toBe(1)
        ->and($quiet->fresh()?->status)->toBe(BookingStatus::Confirmed)
        ->and(ChangeHistory::query()->where('event', 'booking.overdue_flagged')->where('subject_id', $quiet->id)->exists())->toBeTrue();

    $heard->forceFill(['status' => BookingStatus::Cancelled])->save();
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('base_key', AlertKeys::overdue($heard->id, '2026-09-01'))->first()?->resolution)
        ->toBe('the overdue flag cleared');
});

test('a wire is raised by its listener only once the window is past and resolved when it leaves awaiting wire', function (): void {
    $booking = alertBooking();
    $hours = WireWindow::hours();
    $waiting = wirePayment($booking, now()->subHours($hours)->subMinute());
    $inside = wirePayment($booking, now()->subHour());

    PaymentAwaitingWire::dispatch($inside);
    expect(Alert::query()->where('kind', AlertKind::WireNotReceived)->count())->toBe(0);

    PaymentAwaitingWire::dispatch($waiting);
    expect(Alert::query()->where('base_key', AlertKeys::wire($waiting->id))->count())->toBe(1);

    $quiet = wirePayment($booking, now()->subHours($hours)->subHour());
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('kind', AlertKind::WireNotReceived)->whereNull('resolved_at')->count())->toBe(2);

    $waiting->forceFill(['status' => PaymentStatus::Settled])->save();
    PaymentSettled::dispatch($booking, $waiting);

    expect(Alert::query()->where('base_key', AlertKeys::wire($waiting->id))->first()?->resolution)
        ->toBe('the wire was received or released');

    $quiet->forceFill(['status' => PaymentStatus::Settled])->save();
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('base_key', AlertKeys::wire($quiet->id))->first()?->resolution)
        ->toBe('the wire was received or released');
});

test('a failed delivery is raised by its listener and resolved when a later one is sent', function (): void {
    $booking = alertBooking();
    $failed = delivery($booking, DeliveryStatus::Failed);
    DeliveryOutcomeRecorded::dispatch($failed);

    expect(Alert::query()->where('base_key', AlertKeys::delivery(null, $booking->id, DeliveryKind::Invoice))->count())->toBe(1);

    $other = alertBooking(['reference' => 'ANK-DEL-QUIET']);
    delivery($other, DeliveryStatus::Failed);
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('kind', AlertKind::DeliveryFailed)->whereNull('resolved_at')->count())->toBe(2);

    $sent = delivery($booking, DeliveryStatus::Sent);
    DeliveryOutcomeRecorded::dispatch($sent);

    expect(Alert::query()->where('booking_id', $booking->id)->where('kind', AlertKind::DeliveryFailed)->first()?->resolution)
        ->toBe('a later delivery was sent');

    delivery($other, DeliveryStatus::Sent);
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('booking_id', $other->id)->where('kind', AlertKind::DeliveryFailed)->first()?->resolution)
        ->toBe('a later delivery was sent');
});

test('an sla breach is sweep only and records why the task left the predicate', function (): void {
    $closed = slaTask('Respond', now()->subHour());
    $moved = slaTask('Call back', now()->subHour());

    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('kind', AlertKind::SlaBreach)->whereNull('resolved_at')->count())->toBe(2)
        ->and(Alert::query()->where('crm_task_id', $closed->id)->first()?->crm_task_id)->toBe($closed->id);

    Artisan::call('iconic:alerts');
    expect(Alert::query()->where('kind', AlertKind::SlaBreach)->count())->toBe(2);

    $closed->forceFill(['status' => TaskStatus::Done, 'closed_at' => now()])->save();
    $moved->forceFill(['due_at' => now()->addHour()])->save();
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('crm_task_id', $closed->id)->first()?->resolution)->toBe('the task closed')
        ->and(Alert::query()->where('crm_task_id', $moved->id)->first()?->resolution)->toBe('the task is no longer past its due time');
});

test('acknowledging an open alert leaves it unresolved and removes it from the open list', function (): void {
    $this->seed(DemoUsersSeeder::class);
    overdueBooking('ANK-ACK');
    Artisan::call('iconic:alerts');
    $carolina = User::query()->where('email', 'carolina@iconic.test')->firstOrFail();
    $alert = Alert::query()->firstOrFail();

    $response = $this->actingAs($carolina)->postJson('/api/alerts/'.$alert->id.'/acknowledge')->assertOk();

    expect($response->json('data.state'))->toBe('acknowledged')
        ->and($response->json('data.resolved_at'))->toBeNull()
        ->and($alert->fresh()?->resolved_at)->toBeNull();

    $open = $this->actingAs($carolina)->getJson('/api/alerts?state=open')->assertOk();
    expect(collect($open->json('data'))->pluck('id'))->not->toContain($alert->id);

    $this->actingAs($carolina)->postJson('/api/alerts/'.$alert->id.'/acknowledge')->assertStatus(422);
});

test('carolina mateo lucia and the cfo see only their kinds', function (): void {
    $this->seed(DemoUsersSeeder::class);
    seedEveryKind();

    $carolina = User::query()->where('email', 'carolina@iconic.test')->firstOrFail();
    $mateo = User::query()->where('email', 'mateo@iconic.test')->firstOrFail();
    $lucia = User::query()->where('email', 'lucia@iconic.test')->firstOrFail();
    $cfo = User::query()->where('email', 'cfo@iconic.test')->firstOrFail();

    expect(listedKinds($carolina))->toBe([
        AlertKind::CommissionCap->value,
        AlertKind::DeliveryFailed->value,
        AlertKind::OverdueBalance->value,
        AlertKind::SlaBreach->value,
        AlertKind::WireNotReceived->value,
    ])
        ->and(listedKinds($cfo))->toBe([AlertKind::WireNotReceived->value])
        ->and(listedKinds($mateo))->toBe([])
        ->and(listedKinds($lucia))->toBe([]);

    $wire = Alert::query()->where('kind', AlertKind::WireNotReceived)->firstOrFail();
    $this->actingAs($mateo)->postJson('/api/alerts/'.$wire->id.'/acknowledge')->assertForbidden();
    $this->actingAs($cfo)->postJson('/api/alerts/'.$wire->id.'/acknowledge')->assertOk();
});

test('a user with neither panel section cannot read the inbox', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/alerts')->assertForbidden();
});

test('resolving and restoring a condition inserts one hash suffix row', function (): void {
    $booking = alertBooking(['status' => BookingStatus::OnHoldAgency]);
    Artisan::call('iconic:alerts');
    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('base_key', AlertKeys::cap($booking->id))->count())->toBe(1);

    $booking->forceFill(['status' => BookingStatus::Confirmed])->save();
    Artisan::call('iconic:alerts');
    $booking->forceFill(['status' => BookingStatus::OnHoldAgency])->save();

    $raise = app(RaiseAlert::class);
    $first = $raise->handle(AlertKind::CommissionCap, AlertKeys::cap($booking->id), 'Cap', 'Held.', bookingId: $booking->id);
    $second = $raise->handle(AlertKind::CommissionCap, AlertKeys::cap($booking->id), 'Cap', 'Held.', bookingId: $booking->id);

    expect($first->id)->toBe($second->id)
        ->and($first->idempotency_key)->toBe(AlertKeys::cap($booking->id).'#2')
        ->and(Alert::query()->where('base_key', AlertKeys::cap($booking->id))->whereNull('resolved_at')->count())->toBe(1);
});

test('two raises for one base key leave one unresolved row and a unique clash returns that row', function (): void {
    $booking = alertBooking(['status' => BookingStatus::OnHoldAgency]);
    $key = AlertKeys::cap($booking->id);
    $raise = app(RaiseAlert::class);

    $first = $raise->handle(AlertKind::CommissionCap, $key, 'Cap', 'Held.', bookingId: $booking->id);
    $second = $raise->handle(AlertKind::CommissionCap, $key, 'Cap again', 'Held.', bookingId: $booking->id);

    expect($first->id)->toBe($second->id)
        ->and(Alert::query()->where('base_key', $key)->whereNull('resolved_at')->count())->toBe(1)
        ->and(ChangeHistory::query()->where('event', 'alert.raised')->where('subject_id', $first->id)->count())->toBe(1);

    app(ResolveAlert::class)->handle($first, 'cleared for the race');

    $fired = false;
    Alert::creating(function (Alert $alert) use (&$fired, $key, $booking): void {
        if ($fired || $alert->base_key !== $key) {
            return;
        }

        $fired = true;
        DB::table('alerts')->insert([
            'kind' => AlertKind::CommissionCap->value,
            'severity' => AlertSeverity::Warn->value,
            'title' => 'raced',
            'sentence' => 'raced',
            'booking_id' => $booking->id,
            'base_key' => $key,
            'idempotency_key' => $key.'#2',
            'raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $raced = $raise->handle(AlertKind::CommissionCap, $key, 'Cap', 'Held.', bookingId: $booking->id);

    expect($fired)->toBeTrue()
        ->and($raced->title)->toBe('raced')
        ->and($raced->idempotency_key)->toBe($key.'#2')
        ->and(Alert::query()->where('base_key', $key)->whereNull('resolved_at')->count())->toBe(1)
        ->and(ChangeHistory::query()->where('event', 'alert.raised')->where('subject_id', $raced->id)->exists())->toBeFalse();
});

test('scopeOverdue and isOverdue agree', function (): void {
    $overdue = overdueBooking('ANK-TWIN-OVER');
    $future = alertBooking(['reference' => 'ANK-TWIN-FUTURE', 'balance_due_date_override' => '2026-12-01']);
    $held = overdueBooking('ANK-TWIN-HELD', BookingStatus::OnHoldAgency);
    $cancelled = overdueBooking('ANK-TWIN-CANCELLED', BookingStatus::Cancelled);
    $paid = overdueBooking('ANK-TWIN-PAID');
    Payment::factory()->create([
        'booking_id' => $paid->id,
        'amount' => $paid->total,
        'status' => PaymentStatus::Settled,
    ]);

    $scope = Booking::query()->overdue()->pluck('id')->all();

    foreach (Booking::query()->get() as $booking) {
        expect($booking->isOverdue())->toBe(in_array($booking->id, $scope, true));
    }

    expect($scope)->toContain($overdue->id)
        ->and($scope)->toContain($held->id)
        ->and($scope)->not->toContain($future->id)
        ->and($scope)->not->toContain($cancelled->id)
        ->and($scope)->not->toContain($paid->id);
});

test('the wire sql predicate matches endsAtFor and skips a window that ends now', function (): void {
    $booking = alertBooking();
    $hours = WireWindow::hours();
    $cutoff = now()->subHours($hours);

    $past = wirePayment($booking, $cutoff->copy()->subSecond());
    $boundary = wirePayment($booking, $cutoff);
    $fresh = wirePayment($booking, now());
    $settled = Payment::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentStatus::Settled,
        'method' => PaymentMethod::Wire,
        'created_at' => $cutoff->copy()->subDay(),
    ]);

    $sql = Payment::query()->pastWireWindow()->orderBy('id')->pluck('id')->all();
    $php = Payment::query()->orderBy('id')->get()->filter(function (Payment $payment): bool {
        $ends = WireWindow::endsAtFor($payment);

        return $ends instanceof CarbonImmutable && $ends->lt(now());
    })->pluck('id')->all();

    expect($sql)->toBe($php)
        ->and($sql)->toContain($past->id)
        ->and($sql)->not->toContain($boundary->id)
        ->and($sql)->not->toContain($fresh->id)
        ->and($sql)->not->toContain($settled->id);
});

test('a still past due date closes the old overdue key and opens the new one', function (): void {
    $booking = overdueBooking('ANK-DUE');
    Artisan::call('iconic:alerts');

    $booking->forceFill(['balance_due_date_override' => '2026-09-10'])->save();
    Artisan::call('iconic:alerts');

    $rows = Alert::query()->where('booking_id', $booking->id)->where('kind', AlertKind::OverdueBalance)->get();
    $open = $rows->whereNull('resolved_at');

    expect($rows)->toHaveCount(2)
        ->and($open)->toHaveCount(1)
        ->and($open->first()?->base_key)->toBe(AlertKeys::overdue($booking->id, '2026-09-10'))
        ->and($rows->firstWhere('base_key', AlertKeys::overdue($booking->id, '2026-09-01'))?->resolution)
        ->toBe('Due date changed to 2026-09-10');
});

test('a future due date clears the overdue alert and raises nothing', function (): void {
    $booking = overdueBooking('ANK-FUTURE-DUE');
    Artisan::call('iconic:alerts');

    $booking->forceFill(['balance_due_date_override' => '2026-10-01'])->save();
    Artisan::call('iconic:alerts');

    $rows = Alert::query()->where('booking_id', $booking->id)->where('kind', AlertKind::OverdueBalance)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->resolution)->toBe('the overdue flag cleared')
        ->and($rows->whereNull('resolved_at'))->toHaveCount(0);
});

test('the sweep query count stays flat when extra bookings match no predicate', function (): void {
    overdueBooking('ANK-FLAT');
    Artisan::call('iconic:alerts');

    DB::flushQueryLog();
    DB::enableQueryLog();
    Artisan::call('iconic:alerts');
    $before = count(DB::getQueryLog());

    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    Booking::factory()->count(4)->create([
        'departure_id' => $departure->id,
        'status' => BookingStatus::PendingPayment,
        'balance_due_date_override' => '2027-06-01',
    ]);

    DB::flushQueryLog();
    Artisan::call('iconic:alerts');

    expect(count(DB::getQueryLog()))->toBe($before);
});

test('a critical alert emails each audience user once and freezes that list', function (): void {
    Mail::fake();
    $first = adminUser(['email' => 'alerts-first@iconic.test']);
    $disabled = adminUser([
        'email' => 'alerts-disabled@iconic.test',
        'status' => UserStatus::Disabled,
        'disabled_at' => now(),
    ]);
    $alert = criticalOverdue('Critical balance', 'ANK-MAIL is overdue.');

    Artisan::call('iconic:alerts');

    Mail::assertSent(AlertMail::class, function (AlertMail $mail) use ($first): bool {
        return $mail->hasTo($first->email)
            && $mail->title === 'Critical balance'
            && $mail->sentence === 'ANK-MAIL is overdue.'
            && str_contains($mail->url, 'http://localhost:3001/rms/reservations/bookings');
    });
    Mail::assertNotSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo($disabled->email));

    expect(AlertNotification::query()->where('alert_id', $alert->id)->count())->toBe(1)
        ->and(AlertNotification::query()->where('user_id', $first->id)->first()?->status)->toBe(AlertNotificationStatus::Sent)
        ->and($alert->fresh()?->emailed_at)->not->toBeNull();

    Artisan::call('iconic:alerts');
    Mail::assertSentCount(1);

    adminUser(['email' => 'alerts-later@iconic.test']);
    Artisan::call('iconic:alerts');

    Mail::assertSentCount(1);
    expect(AlertNotification::query()->where('alert_id', $alert->id)->count())->toBe(1);
});

test('a resolved critical alert and a warn alert send nothing', function (): void {
    Mail::fake();
    adminUser();
    $critical = Alert::factory()->critical()->create();
    app(ResolveAlert::class)->handle($critical, 'cleared before mail');
    Alert::factory()->create();

    Artisan::call('iconic:alerts');

    Mail::assertNothingSent();
    expect(AlertNotification::query()->count())->toBe(0);
});

test('a failed critical send is retried once and then left failed', function (): void {
    $sends = 0;
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$sends) {
        $sends++;
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andThrow(new RuntimeException('mailbox down'));

        return $pending;
    });

    adminUser(['email' => 'alerts-retry@iconic.test']);
    $alert = criticalOverdue('Retry', 'Retry the critical alert.');

    Artisan::call('iconic:alerts');
    $row = AlertNotification::query()->where('alert_id', $alert->id)->first();

    expect($sends)->toBe(1)
        ->and($row?->status)->toBe(AlertNotificationStatus::Failed)
        ->and($row?->attempts)->toBe(1)
        ->and($alert->fresh()?->emailed_at)->toBeNull();

    Artisan::call('iconic:alerts');
    $row = $row?->fresh();

    expect($sends)->toBe(2)
        ->and($row?->status)->toBe(AlertNotificationStatus::Failed)
        ->and($row?->attempts)->toBe(2)
        ->and($alert->fresh()?->emailed_at)->not->toBeNull()
        ->and(AlertNotification::query()->where('alert_id', $alert->id)->count())->toBe(1);

    Artisan::call('iconic:alerts');
    expect($sends)->toBe(2);
});

test('a resolved critical alert is not retried', function (): void {
    $sends = 0;
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$sends) {
        $sends++;
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andThrow(new RuntimeException('mailbox down'));

        return $pending;
    });

    adminUser();
    $alert = criticalOverdue('Resolved before retry', 'Do not retry this.');
    Artisan::call('iconic:alerts');
    app(ResolveAlert::class)->handle($alert, 'cleared before the retry');
    Artisan::call('iconic:alerts');

    expect($sends)->toBe(1)
        ->and(AlertNotification::query()->first()?->attempts)->toBe(1);
});

test('the sweep does not change bookings payments tasks or deliveries', function (): void {
    $booking = overdueBooking('ANK-SIDE');
    $payment = wirePayment($booking, now()->subDays(5));
    $task = slaTask('Side', now()->subHour());
    $row = delivery($booking, DeliveryStatus::Blocked);

    $before = [
        Booking::query()->count(),
        Payment::query()->count(),
        CrmTask::query()->count(),
        Delivery::query()->count(),
        $booking->status->value,
        $payment->status->value,
        $task->status->value,
        $row->status->value,
    ];

    Artisan::call('iconic:alerts');

    expect([
        Booking::query()->count(),
        Payment::query()->count(),
        CrmTask::query()->count(),
        Delivery::query()->count(),
        $booking->fresh()?->status->value,
        $payment->fresh()?->status->value,
        $task->fresh()?->status->value,
        $row->fresh()?->status->value,
    ])->toBe($before);

    expect(Alert::query()->count())->toBe(4);
});

test('the crm section response has no sensitive fields', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $booking = alertBooking();
    delivery($booking, DeliveryStatus::Failed);
    Artisan::call('iconic:alerts');

    $carolina = User::query()->where('email', 'carolina@iconic.test')->firstOrFail();
    $response = $this->actingAs($carolina)->getJson('/api/alerts?section=crm');

    $response->assertOk();
    assertNoSensitiveFields($response);
    expect($response->json('data.0.kind'))->toBe(AlertKind::DeliveryFailed->value);
});

test('meta counts ignore section and equal the sum of the open section lists', function (): void {
    $this->seed(DemoUsersSeeder::class);
    seedEveryKind();
    $carolina = User::query()->where('email', 'carolina@iconic.test')->firstOrFail();

    $plain = $this->actingAs($carolina)->getJson('/api/alerts?state=acknowledged')->assertOk();
    $rms = $this->actingAs($carolina)->getJson('/api/alerts?section=rms&state=resolved')->assertOk();
    $crm = $this->actingAs($carolina)->getJson('/api/alerts?section=crm&severity=INFO')->assertOk();

    expect($plain->json('meta.counts'))->toBe($rms->json('meta.counts'))
        ->and($plain->json('meta.counts'))->toBe($crm->json('meta.counts'));

    $openRms = $this->actingAs($carolina)->getJson('/api/alerts?state=open&section=rms')->assertOk();
    $openCrm = $this->actingAs($carolina)->getJson('/api/alerts?state=open&section=crm')->assertOk();
    $counts = $plain->json('meta.counts');

    expect($counts['INFO'] + $counts['WARN'] + $counts['CRITICAL'])
        ->toBe($openRms->json('meta.total') + $openCrm->json('meta.total'))
        ->and($openRms->json('meta.total'))->toBe(3)
        ->and($openCrm->json('meta.total'))->toBe(2);
});

test('kinds lists the registry and a guest response references the response row', function (): void {
    $this->actingAs(adminUser())->getJson('/api/alerts/kinds')
        ->assertOk()
        ->assertJsonCount(13, 'data')
        ->assertJsonPath('data.0.emails', false)
        ->assertJsonPath('data.0.section', 'rms');

    expect(AlertRegistry::all())->toHaveCount(13);

    $booking = alertBooking();
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    $response = GuestResponse::query()->create([
        'guest_id' => $guest->id,
        'booking_id' => $booking->id,
        'score' => 6,
        'source' => GuestResponseSource::Staff,
        'responded_at' => now(),
    ]);
    $alert = Alert::factory()->create(['guest_response_id' => $response->id]);
    expect($alert->fresh()?->guest_response_id)->toBe($response->id);
    expect(fn () => $alert->delete())->toThrow(LogicException::class);
});

function alertBooking(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture('2027-11-07');
    unset($overrides['departure']);

    if (! $departure instanceof Departure) {
        throw new RuntimeException('departure override must be a Departure.');
    }

    $cabinId = $departure->yacht->cabins->first()?->id;

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $cabinId,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'balance_days' => 120,
        'balance_due_date_override' => '2027-01-01',
        ...$overrides,
    ]);
}

function overdueBooking(string $reference, BookingStatus $status = BookingStatus::Confirmed): Booking
{
    return alertBooking([
        'reference' => $reference,
        'status' => $status,
        'balance_due_date_override' => '2026-09-01',
        'departure' => ReservationFixtures::anamaraDeparture('2027-11-07'),
    ]);
}

function wirePayment(Booking $booking, CarbonImmutable|DateTimeInterface $createdAt): Payment
{
    return Payment::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentStatus::AwaitingWire,
        'method' => PaymentMethod::Wire,
        'amount' => 5000,
        'created_at' => $createdAt,
    ]);
}

function delivery(Booking $booking, DeliveryStatus $status): Delivery
{
    return Delivery::factory()->create([
        'booking_id' => $booking->id,
        'document_id' => null,
        'kind' => DeliveryKind::Invoice,
        'status' => $status,
        'to' => ['guest@example.com'],
    ]);
}

function slaTask(string $title, CarbonImmutable|DateTimeInterface $dueAt): CrmTask
{
    return CrmTask::query()->create([
        'title' => $title,
        'context' => $title,
        'due_at' => $dueAt,
        'source' => TaskSource::System,
        'kind' => TaskKind::Manual,
        'status' => TaskStatus::Open,
        'idempotency_key' => 'sla-test:'.$title,
    ]);
}

function criticalOverdue(string $title, string $sentence): Alert
{
    $booking = overdueBooking('ANK-CRIT-'.bookingSuffix());
    $key = AlertKeys::overdue($booking->id, '2026-09-01');

    return Alert::factory()->critical()->create([
        'booking_id' => $booking->id,
        'base_key' => $key,
        'idempotency_key' => $key,
        'title' => $title,
        'sentence' => $sentence,
    ]);
}

function bookingSuffix(): string
{
    return str_replace('.', '', uniqid('', true));
}

function seedEveryKind(): void
{
    overdueBooking('ANK-ALL-OVER');
    alertBooking(['status' => BookingStatus::OnHoldAgency, 'reference' => 'ANK-ALL-CAP']);
    wirePayment(alertBooking(['reference' => 'ANK-ALL-WIRE']), now()->subDays(5));
    slaTask('All sla', now()->subHour());
    delivery(alertBooking(['reference' => 'ANK-ALL-DEL']), DeliveryStatus::Failed);
    Artisan::call('iconic:alerts');
}

/**
 * @return list<string>
 */
function listedKinds(User $user): array
{
    $response = test()->actingAs($user)->getJson('/api/alerts');
    $response->assertOk();

    return collect($response->json('data'))->pluck('kind')->sort()->values()->all();
}
