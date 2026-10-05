<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ConfigKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RefundRequestStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Models\RefundRequest;
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

test('extending the end of a stay claims the added nights at current rates', function (): void {
    $booking = stayChangeBooking();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify/preview', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
        ])
        ->assertOk()
        ->assertJsonPath('new_total', 420)
        ->assertJsonPath('credit', 0)
        ->assertJsonPath('penalty', 0)
        ->assertJsonPath('nights', 4);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Guest stays through the weekend',
        ])
        ->assertOk()
        ->assertJsonPath('total', 420)
        ->assertJsonPath('stay.check_out', '2026-02-08')
        ->assertJsonPath('stay.nights', 4);

    $history = ChangeHistory::query()->where('event', 'booking.stay_modified')->firstOrFail();
    expect($history->before['check_in'] ?? null)->toBe('2026-02-04')
        ->and($history->before['check_out'] ?? null)->toBe('2026-02-06')
        ->and($history->after['check_out'] ?? null)->toBe('2026-02-08')
        ->and($history->reason)->toBe('Guest stays through the weekend');
    expect(RoomNightClaim::query()->where('room_id', $booking->room_id)->whereNull('released_at')->count())->toBe(4);
    expect(collect($booking->fresh()?->night_lines)->last()['rates_version_id'] ?? null)->not->toBeNull();
});

test('extending the start prices only the new nights', function (): void {
    $booking = stayChangeBooking();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-02',
            'check_out' => '2026-02-06',
            'reason' => 'Arrive earlier',
        ])
        ->assertOk()
        ->assertJsonPath('total', 400)
        ->assertJsonPath('stay.check_in', '2026-02-02')
        ->assertJsonPath('stay.nights', 4);
});

test('a longer stay discounts only the added nights', function (): void {
    $booking = stayChangeBooking();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-11',
            'reason' => 'Stay the week',
        ])
        ->assertOk()
        ->assertJsonPath('total', 668)
        ->assertJsonPath('stay.nights', 7);

    expect(collect($booking->fresh()?->price_lines)->firstWhere('code', 'length_of_stay')['amount'] ?? null)->toBe(-52);
});

test('shortening credits the sold night and adds the cancellation penalty', function (): void {
    $booking = stayChangeBooking();
    $this->travelTo(CarbonImmutable::parse('2025-10-01 12:00:00', BusinessTime::zone()));

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-05',
            'reason' => 'Leave a day early',
        ])
        ->assertOk()
        ->assertJsonPath('total', 105)
        ->assertJsonPath('stay.nights', 1);

    expect(collect($booking->fresh()?->price_lines)->firstWhere('code', 'modification_penalty')['amount'] ?? null)->toBe(5);
});

test('penalty bands follow days to arrival and a waiver needs the permission', function (): void {
    $half = stayChangeBooking();
    $this->travelTo(CarbonImmutable::parse('2025-11-06 12:00:00', BusinessTime::zone()));
    $this->actingAs($half->owner)
        ->postJson('/api/rms/bookings/'.$half->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-05',
            'reason' => 'Shorten',
        ])
        ->assertOk()
        ->assertJsonPath('total', 150);

    $this->travelTo(CarbonImmutable::parse('2026-02-01 12:00:00', BusinessTime::zone()));
    $full = stayChangeBooking();
    $exec = salesExecUser();
    $owned = stayChangeBooking(actor: $exec);

    $this->actingAs($full->owner)
        ->postJson('/api/rms/bookings/'.$full->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-05',
            'reason' => 'Shorten',
            'reason_code' => 'GUEST_FRIENDLY',
        ])
        ->assertOk()
        ->assertJsonPath('total', 100);

    $this->actingAs($exec)
        ->postJson('/api/rms/bookings/'.$owned->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-05',
            'reason' => 'Shorten',
            'reason_code' => 'GUEST_FRIENDLY',
        ])
        ->assertOk()
        ->assertJsonPath('total', 200);
});

test('shifting by one day keeps the overlap and prices the new night', function (): void {
    $booking = stayChangeBooking();
    $this->travelTo(CarbonImmutable::parse('2025-10-01 12:00:00', BusinessTime::zone()));

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-05',
            'check_out' => '2026-02-07',
            'reason' => 'Shift one day',
        ])
        ->assertOk()
        ->assertJsonPath('total', 215)
        ->assertJsonPath('stay.check_in', '2026-02-05')
        ->assertJsonPath('stay.check_out', '2026-02-07');

    $active = RoomNightClaim::query()->where('holder_id', $booking->id)->whereNull('released_at')->orderBy('night')->pluck('night');
    expect($active->map(fn ($night) => $night instanceof DateTimeInterface ? $night->format('Y-m-d') : (string) $night)->all())
        ->toBe(['2026-02-05', '2026-02-06']);
});

test('a busy room moves the stay onto another room of the same type', function (): void {
    $booking = stayChangeBooking();
    $other = stayChangeRoom('STD', 'F'.substr(uniqid(), -6), 20);
    stayChangeBooking(checkIn: '2026-02-06', checkOut: '2026-02-08', roomId: $booking->room_id, expected: 220);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Need a free room',
        ])
        ->assertOk()
        ->assertJsonPath('room.id', $other->id)
        ->assertJsonPath('total', 420)
        ->assertJsonPath('stay.nights', 4);

    expect(RoomNightClaim::query()->where('room_id', $other->id)->where('holder_id', $booking->id)->whereNull('released_at')->count())->toBe(4);
    expect(RoomNightClaim::query()->where('room_id', $booking->room_id)->where('holder_id', $booking->id)->whereNull('released_at')->count())->toBe(0);
});

test('a busy room refuses the change and leaves the booking alone', function (): void {
    $booking = stayChangeBooking();
    $taken = stayChangeBooking(checkIn: '2026-02-06', checkOut: '2026-02-08', roomId: $booking->room_id, expected: 220);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Need the weekend',
        ])
        ->assertStatus(409);

    $fresh = $booking->fresh();
    expect($fresh?->check_out?->toDateString())->toBe('2026-02-06')
        ->and($fresh?->total)->toBe(200)
        ->and(ChangeHistory::query()->where('event', 'booking.stay_modified')->count())->toBe(0)
        ->and(RoomNightClaim::query()->where('room_id', $booking->room_id)->where('holder_id', $booking->id)->whereNull('released_at')->count())->toBe(2);
    expect($taken->fresh()?->check_in?->toDateString())->toBe('2026-02-06');
});

test('moving to another type keeps the sold price unless reprice is set', function (): void {
    $booking = stayChangeBooking();
    $twin = stayChangeRoom('TWN', 'T'.substr(uniqid(), -6), 2);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'room_id' => $twin->id,
            'reason' => 'Upgrade',
        ])
        ->assertOk()
        ->assertJsonPath('room.id', $twin->id)
        ->assertJsonPath('total', 200);

    expect(ChangeHistory::query()->where('event', 'booking.moved')->count())->toBe(1);
    expect(RoomNightClaim::query()->where('room_id', $twin->id)->whereNull('released_at')->count())->toBe(2);

    $repriced = stayChangeBooking();
    $other = stayChangeRoom('TWN', 'U'.substr(uniqid(), -6), 3);

    $this->actingAs($repriced->owner)
        ->postJson('/api/rms/bookings/'.$repriced->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-06',
            'room_id' => $other->id,
            'reprice' => true,
            'reason' => 'Move and reprice',
        ])
        ->assertOk()
        ->assertJsonPath('room.id', $other->id)
        ->assertJsonPath('total', 180);
});

test('a higher total reopens a fully paid booking and a lower total asks for a refund', function (): void {
    $raised = stayChangeBooking();
    stayChangePay($raised, 200);

    $this->actingAs($raised->owner)
        ->postJson('/api/rms/bookings/'.$raised->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Add the weekend',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Confirmed->value)
        ->assertJsonPath('total', 420);

    $history = ChangeHistory::query()->where('subject_id', $raised->id)->where('event', 'booking.stay_modified')->firstOrFail();
    expect($history->before['status'] ?? null)->toBe(BookingStatus::FullyPaid->value)
        ->and($history->after['status'] ?? null)->toBe(BookingStatus::Confirmed->value);
    expect(RefundRequest::query()->where('booking_id', $raised->id)->count())->toBe(0);

    $lowered = stayChangeBooking();
    stayChangePay($lowered, 200);
    $this->travelTo(CarbonImmutable::parse('2025-10-01 12:00:00', BusinessTime::zone()));

    $this->actingAs($lowered->owner)
        ->postJson('/api/rms/bookings/'.$lowered->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-05',
            'reason' => 'Leave early',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::FullyPaid->value)
        ->assertJsonPath('total', 105);

    $refund = RefundRequest::query()->where('booking_id', $lowered->id)->firstOrFail();
    expect($refund->refund_due)->toBe(95)
        ->and($refund->penalty_amount)->toBe(5)
        ->and($refund->status)->toBe(RefundRequestStatus::Pending)
        ->and($refund->executed_payment_id)->toBeNull();
});

test('an in-house guest can extend on the same room in one history entry', function (): void {
    $booking = stayChangeBooking();
    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/check-in')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::InHouse->value);

    $this->travelTo(CarbonImmutable::parse('2026-02-05 10:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Two more nights',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::InHouse->value)
        ->assertJsonPath('total', 420)
        ->assertJsonPath('stay.nights', 4);

    expect(ChangeHistory::query()->where('event', 'booking.stay_modified')->count())->toBe(1);
    expect(RoomNightClaim::query()->where('room_id', $booking->room_id)->whereNull('released_at')->count())->toBe(4);
});

test('the modification fee is charged once and taxes follow the night delta', function (): void {
    $booking = stayChangeBooking();
    stayChangeRules(function (array $rules): array {
        $rules['modification_fee_usd'] = 25;

        return $rules;
    });

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Add a fee',
        ])
        ->assertOk()
        ->assertJsonPath('total', 445);

    expect(collect($booking->fresh()?->price_lines)->where('code', 'modification_fee'))->toHaveCount(1);

    $taxed = stayChangeBooking();
    stayChangeRules(function (array $rules): array {
        $rules['modification_fee_usd'] = 0;
        $rules['taxes'] = [[
            'code' => 'CITY',
            'label' => 'City tax',
            'basis' => 'PER_NIGHT',
            'amount' => 10,
            'child_exempt_under_age' => null,
            'charged' => true,
            'shown_in_price_panel' => true,
        ]];

        return $rules;
    });

    $this->actingAs($taxed->owner)
        ->postJson('/api/rms/bookings/'.$taxed->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Add a tax',
        ])
        ->assertOk()
        ->assertJsonPath('total', 420);

    $line = collect($taxed->fresh()?->tax_lines)->firstWhere('code', 'CITY');
    expect($line['amount'] ?? null)->toBe(20);
});

test('a checked-out stay and another manager cannot modify it', function (): void {
    $booking = stayChangeBooking();
    $this->travelTo(CarbonImmutable::parse('2026-02-04 15:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)->postJson('/api/rms/bookings/'.$booking->id.'/check-in')->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-02-06 11:00:00', BusinessTime::zone()));
    $this->actingAs($booking->owner)->postJson('/api/rms/bookings/'.$booking->id.'/check-out')->assertOk();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Too late',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->travelTo(CarbonImmutable::parse('2026-02-01 12:00:00', BusinessTime::zone()));
    $open = stayChangeBooking();
    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/'.$open->id.'/modify', [
            'check_in' => '2026-02-04',
            'check_out' => '2026-02-08',
            'reason' => 'Not mine',
        ])
        ->assertForbidden();
});

function stayChangeRoom(string $type, string $code, int $sort): Room
{
    $roomType = RoomType::query()->where('code', $type)->firstOrFail();

    return Room::query()->create([
        'property_id' => $roomType->property_id,
        'room_type_id' => $roomType->id,
        'code' => $code,
        'label' => 'Room '.$code,
        'sort' => $sort,
        'status' => RoomStatus::Active,
    ]);
}

function stayChangeBooking(
    string $checkIn = '2026-02-04',
    string $checkOut = '2026-02-06',
    ?int $roomId = null,
    int $expected = 200,
    ?User $actor = null,
): Booking {
    $actor ??= managerUser();

    if ($roomId === null) {
        stayChangeRoom('STD', 'S'.substr(uniqid(), -6), 1);
    }

    $room = [
        'room_type' => 'STD',
        'adults' => 2,
        'child_ages' => [],
    ];

    if ($roomId !== null) {
        $room['room_id'] = $roomId;
    }

    $id = test()->actingAs($actor)
        ->postJson('/api/rms/bookings', [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'rooms' => [$room],
            'client' => [
                'name' => 'Stay Guest',
                'email' => 'stay-'.uniqid().'@iconic.test',
            ],
            'main_channel' => 'D2C',
            'channel_of_origin' => 'Hotel Booking Engine',
            'expected_total' => $expected,
        ])
        ->assertCreated()
        ->json('bookings.0.id');

    $booking = Booking::query()->findOrFail($id);
    $booking->status = BookingStatus::FullyPaid;
    $booking->save();

    return $booking->refresh();
}

function stayChangePay(Booking $booking, int $amount): void
{
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => $amount,
    ]);
}

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 */
function stayChangeRules(callable $mutate): void
{
    $rules = app(CurrentConfig::class)->businessRules()->toArray();
    $rules = $mutate($rules);
    $version = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);
    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $rules,
        $version->version,
        'STAY-MOD-'.uniqid(),
        adminUser(),
    );
    app(CurrentConfig::class)->forget(ConfigKind::BusinessRules);
}
