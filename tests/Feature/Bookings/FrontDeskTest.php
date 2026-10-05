<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ConfigKind;
use App\Enums\Permission;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-02-01 12:00:00', BusinessTime::zone()));
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
});

test('a guest arriving at 02:00 on the arrival day can be checked in', function (): void {
    $booking = frontDeskBooking();

    $this->travelTo(CarbonImmutable::parse('2026-02-04 02:00:00', BusinessTime::zone()));

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::InHouse->value)
        ->assertJsonPath('stay.check_in', '2026-02-04');

    expect($booking->fresh()?->checked_in_at)->not->toBeNull();
    expect(ChangeHistory::query()->where('event', 'booking.checked_in')->count())->toBe(1);
});

test('check-in is refused the day before arrival and allowed at 00:01', function (): void {
    $booking = frontDeskBooking();

    $this->travelTo(CarbonImmutable::parse('2026-02-03 23:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_in']);

    $this->travelTo(CarbonImmutable::parse('2026-02-04 00:01:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::InHouse->value);
});

test('a back-dated check-in stays inside arrival midnight and now', function (): void {
    $onTime = frontDeskBooking();
    $tooEarly = frontDeskBooking();
    $inTheFuture = frontDeskBooking();
    $this->travelTo(CarbonImmutable::parse('2026-02-05 10:00:00', BusinessTime::zone()));
    $start = BusinessTime::calendarDay('2026-02-04')->utc();

    $this->actingAs($onTime->owner)
        ->postJson('/api/rms/bookings/'.$onTime->id.'/check-in', [
            'at' => $start->toIso8601String(),
        ])
        ->assertOk();

    $this->actingAs($tooEarly->owner)
        ->postJson('/api/rms/bookings/'.$tooEarly->id.'/check-in', [
            'at' => $start->subMinute()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['at']);

    $this->actingAs($inTheFuture->owner)
        ->postJson('/api/rms/bookings/'.$inTheFuture->id.'/check-in', [
            'at' => CarbonImmutable::now()->addMinute()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['at']);
});

test('check-in can move the room and writes one history entry', function (): void {
    $booking = frontDeskBooking();
    $other = frontDeskRoom('102', 10);
    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in', [
            'room_id' => $other->id,
        ])
        ->assertOk()
        ->assertJsonPath('room.id', $other->id);

    $history = ChangeHistory::query()->where('event', 'booking.checked_in')->get();
    expect($history)->toHaveCount(1);
    expect($history->first()?->after['what'] ?? '')->toContain($other->label);
    expect(RoomNightClaim::query()->where('room_id', $other->id)->whereNull('released_at')->count())->toBe(2);
    expect(ChangeHistory::query()->where('event', 'booking.moved')->count())->toBe(0);
});

test('check-in follows the full-payment rule both ways', function (): void {
    $confirmed = frontDeskBooking(BookingStatus::Confirmed);

    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));
    $this->actingAs($confirmed->owner)
        ->postJson('/api/rms/bookings/'.$confirmed->id.'/check-in')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $rules = app(CurrentConfig::class)->businessRules()->toArray();
    $rules['stay']['check_in_requires_full_payment'] = false;
    $version = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);
    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $rules,
        $version->version,
        'DESK-PAY',
        adminUser(),
    );
    app(CurrentConfig::class)->forget(ConfigKind::BusinessRules);

    $this->actingAs($confirmed->owner)
        ->postJson('/api/rms/bookings/'.$confirmed->id.'/check-in')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::InHouse->value);
});

test('an early departure credits the unused nights', function (): void {
    $booking = frontDeskBooking();
    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-02-05 09:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-out')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-out', [
            'reason' => 'Flight changed',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::CheckedOut->value)
        ->assertJsonPath('stay.check_out', '2026-02-05')
        ->assertJsonPath('total', 100);

    $fresh = $booking->fresh();
    expect(collect($fresh?->price_lines)->firstWhere('code', 'EARLY_DEPARTURE')['amount'] ?? null)->toBe(-100);
    expect(RoomNightClaim::query()->where('room_id', $fresh?->room_id)->whereNull('released_at')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'booking.stay_shortened')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'booking.checked_out')->count())->toBe(1);
});

test('a late check-out is recorded and not charged', function (): void {
    $booking = frontDeskBooking();
    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)->postJson('/api/rms/bookings/'.$booking->id.'/check-in')->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-02-06 12:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-out')
        ->assertOk()
        ->assertJsonPath('total', 200);

    $history = ChangeHistory::query()->where('event', 'booking.checked_out')->firstOrFail();
    expect($history->after['what'] ?? '')->toContain('late check-out recorded, not charged');
    expect($history->after['late'] ?? null)->toBeTrue();
});

test('a no-show releases nights after arrival and records a charge, not a payment', function (): void {
    $booking = frontDeskBooking();

    $this->travelTo(CarbonImmutable::parse('2026-02-04 02:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/no-show', ['reason' => 'Did not arrive'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_in']);

    $this->travelTo(CarbonImmutable::parse('2026-02-05 10:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/no-show', ['reason' => 'Did not arrive'])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::NoShow->value)
        ->assertJsonPath('total', 200)
        ->assertJsonPath('price_lines.0.code', 'NO_SHOW')
        ->assertJsonPath('price_lines.0.amount', 200);

    $nights = RoomNightClaim::query()->where('room_id', $booking->room_id)->orderBy('night')->get();
    expect($nights->first()?->released_at)->toBeNull();
    expect($nights->last()?->released_at)->not->toBeNull();
    expect(Payment::query()->count())->toBe(0);
    expect(ChangeHistory::query()->where('event', 'booking.no_show')->count())->toBe(1);
});

test('front desk is permission gated and undo is admin and same day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));
    $exec = salesExecUser();
    $booking = frontDeskBooking(actor: $exec);

    $role = Role::factory()->create(['permissions' => [Permission::PanelRms]]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]))
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertForbidden();

    $this->actingAs($exec)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertOk();

    $this->actingAs($exec)
        ->postJson('/api/rms/bookings/'.$booking->id.'/undo-check-in', ['reason' => 'Wrong guest'])
        ->assertForbidden();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/undo-check-in', ['reason' => 'Wrong guest'])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::FullyPaid->value);

    expect($booking->fresh()?->checked_in_at)->toBeNull();
    expect(ChangeHistory::query()->where('event', 'booking.check_in_undone')->count())->toBe(1);

    $this->actingAs($exec)->postJson('/api/rms/bookings/'.$booking->id.'/check-in')->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-02-05 10:00:00', BusinessTime::zone()));
    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/undo-check-in', ['reason' => 'Too late'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['checked_in_at']);
});

function frontDeskRoom(string $code, int $sort): Room
{
    $type = RoomType::query()->where('code', 'STD')->firstOrFail();

    return Room::query()->create([
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'code' => $code,
        'label' => 'Room '.$code,
        'sort' => $sort,
        'status' => RoomStatus::Active,
    ]);
}

function frontDeskBooking(BookingStatus $status = BookingStatus::FullyPaid, ?User $actor = null): Booking
{
    $actor ??= managerUser();
    frontDeskRoom('R'.substr(uniqid(), -6), 1);

    $id = test()->actingAs($actor)
        ->postJson('/api/rms/bookings', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-06',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [],
            ]],
            'client' => [
                'name' => 'Desk Guest',
                'email' => 'desk-'.uniqid().'@iconic.test',
            ],
            'main_channel' => 'D2C',
            'channel_of_origin' => 'Hotel Booking Engine',
            'expected_total' => 200,
        ])
        ->assertCreated()
        ->json('bookings.0.id');

    $booking = Booking::query()->findOrFail($id);
    $booking->status = $status;
    $booking->save();

    return $booking->refresh();
}
