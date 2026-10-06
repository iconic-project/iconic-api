<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\PaymentStatus;
use App\Enums\ReleaseReason;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Models\RoomNightClaim;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function bookedCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture();
    unset($overrides['departure']);
    $actor = $overrides['actor'] ?? managerUser();
    unset($overrides['actor']);
    $cabin = $overrides['cabin_code'] ?? 'S1';
    unset($overrides['cabin_code']);

    $id = test()->actingAs($actor)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => $cabin, 'adults' => 2, 'children' => 0]],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $booking = Booking::query()->findOrFail($id);

    if ($overrides !== []) {
        $booking->update($overrides);
        $booking->refresh();
    }

    return $booking;
}

function requestedHold(bool $expired = false, string $cabinCode = 'S2'): Booking
{
    $departure = ReservationFixtures::anamaraDeparture();
    $cabin = $departure->property->cabins->firstWhere('code', $cabinCode);
    $actor = managerUser();
    $booking = Booking::factory()->create([
        'reference' => null,
        'request_reference' => 'ANK-R-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 99), 4, '0', STR_PAD_LEFT),
        'status' => BookingStatus::Requested,
        'departure_id' => $departure->id,
        'room_id' => $cabin?->id,
        'owner_id' => $actor->id,
    ]);

    DB::transaction(function () use ($departure, $cabin, $booking): void {
        app(ClaimService::class)->claim($departure->stayDates(),
            collect([$cabin]),
            $booking,
            ClaimKind::Hold,
            HoldType::Request,
            now()->addDay(),
        );
    });

    if ($expired) {
        RoomNightClaim::query()
            ->where('holder_id', $booking->id)
            ->whereNull('released_at')
            ->update([
                'released_at' => now(),
                'release_reason' => ReleaseReason::Expired,
            ]);
    }

    return $booking->refresh();
}

test('every legal transition is accepted', function (string $from, string $to, bool $needsReason): void {
    $booking = $from === 'REQUESTED'
        ? requestedHold()
        : bookedCabin(['status' => BookingStatus::from($from)]);

    $payload = ['to' => $to];
    if ($needsReason) {
        $payload['reason'] = 'Logged';
    }

    test()->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', $payload)
        ->assertOk()
        ->assertJsonPath('status', $to);
})->with([
    ['PENDING_PAYMENT', 'CONFIRMED', false],
    ['PENDING_PAYMENT', 'CANCELLED', true],
    ['CONFIRMED', 'FULLY_PAID', true],
    ['CONFIRMED', 'CANCELLED', true],
    ['FULLY_PAID', 'CANCELLED_POSTPAID', true],
    ['REQUESTED', 'PENDING_PAYMENT', false],
    ['REQUESTED', 'CONFIRMED', false],
    ['REQUESTED', 'RELEASED', true],
    ['REQUESTED', 'CANCELLED', true],
]);

test('illegal targets are 422 listing the legal ones', function (): void {
    $booking = bookedCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'IN_HOUSE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to'])
        ->assertJsonPath('errors.to.0', 'Cannot change status from CONFIRMED to IN_HOUSE. Allowed: FULLY_PAID, CANCELLED. Check in at POST /api/rms/bookings/{booking}/check-in.');
});

test('a required reason is 422 when missing and optional reasons may be omitted', function (): void {
    $booking = bookedCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'CANCELLED'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'FULLY_PAID', 'reason' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $pending = bookedCabin(['status' => BookingStatus::PendingPayment, 'cabin_code' => 'S3']);

    $this->actingAs($pending->owner)
        ->postJson('/api/rms/bookings/'.$pending->id.'/transition', ['to' => 'CONFIRMED'])
        ->assertOk();
});

test('lucia cannot transition mateo\'s booking and carolina can', function (): void {
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    $carolina = adminUser(['name' => 'Carolina M.']);
    $booking = bookedCabin(['actor' => $mateo]);

    $this->actingAs($lucia)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'CONFIRMED'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Blocked: own-records rule.');

    $this->actingAs($carolina)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'CONFIRMED'])
        ->assertOk()
        ->assertJsonPath('status', 'CONFIRMED');
});

test('in house and checked out are not generic transitions', function (): void {
    $booking = bookedCabin(['status' => BookingStatus::FullyPaid]);

    $this->travelTo(CarbonImmutable::parse('2027-11-07 18:00:00', 'UTC'));
    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'IN_HOUSE'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.to.0', 'Cannot change status from FULLY_PAID to IN_HOUSE. Allowed: CONFIRMED, CANCELLED_POSTPAID. Check in at POST /api/rms/bookings/{booking}/check-in.');

    $booking->status = BookingStatus::InHouse;
    $booking->save();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'CHECKED_OUT'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.to.0', 'Cannot change status from IN_HOUSE to CHECKED_OUT. Allowed: none. Check out at POST /api/rms/bookings/{booking}/check-out.');

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'NO_SHOW'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.to.0', 'Cannot change status from IN_HOUSE to NO_SHOW. Allowed: none. Mark a no-show at POST /api/rms/bookings/{booking}/no-show.');
});

test('confirming a request converts the hold and assigns a booking reference', function (): void {
    $booking = requestedHold();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', ['to' => 'CONFIRMED'])
        ->assertOk()
        ->assertJsonPath('status', 'CONFIRMED')
        ->assertJsonPath('request_reference', $booking->request_reference);

    $booking->refresh();
    expect($booking->reference)->toStartWith('ANK-');
    expect($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);
    expect($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Hold)->count())->toBe(0);
});

test('an expired hold is reclaimed or 409 if the cabin was taken', function (): void {
    $expired = requestedHold(expired: true);

    $this->actingAs($expired->owner)
        ->postJson('/api/rms/bookings/'.$expired->id.'/transition', ['to' => 'CONFIRMED'])
        ->assertOk()
        ->assertJsonPath('status', 'CONFIRMED');

    expect($expired->fresh()->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);

    $taken = requestedHold(expired: true, cabinCode: 'S6');
    $departure = $taken->departure;
    $cabin = $taken->cabin;
    $other = bookedCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2027-11-14'),
        'cabin_code' => 'S1',
    ]);
    $other->update(['room_id' => $cabin?->id, 'departure_id' => $departure->id]);
    DB::transaction(function () use ($departure, $cabin, $other): void {
        $other->claims()->whereNull('released_at')->update([
            'released_at' => now(),
            'release_reason' => ReleaseReason::Moved,
        ]);
        app(ClaimService::class)->claim($departure->stayDates(), collect([$cabin]), $other, ClaimKind::Booking);
    });

    $this->actingAs($taken->owner)
        ->postJson('/api/rms/bookings/'.$taken->id.'/transition', ['to' => 'CONFIRMED'])
        ->assertStatus(409)
        ->assertJsonPath('message', "The cabin was taken after this request's hold expired.");

    expect($taken->fresh()->status)->toBe(BookingStatus::Requested);
});

test('cancelling a confirmed booking frees the cabin and stores the reason', function (): void {
    $booking = bookedCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();

    $snapshot = app(Availability::class)->forDepartures(collect([$booking->departure]))[$booking->departure_id];
    $s1 = collect($snapshot->cabins)->firstWhere('cabin.code', 'S1');
    expect($s1['state'])->toBe('FREE');

    $history = ChangeHistory::query()->where('event', 'booking.status_changed')->latest('id')->firstOrFail();
    expect($history->reason)->toBe('Guest withdrew');
});

test('manual fully paid records the prototype wording', function (): void {
    $booking = bookedCabin(['status' => BookingStatus::Confirmed]);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'FULLY_PAID',
            'reason' => 'Wire seen',
        ])
        ->assertOk();

    $history = ChangeHistory::query()->where('event', 'booking.status_changed')->latest('id')->firstOrFail();
    expect($history->after['what'] ?? '')->toContain('marked manually — USD 26,600 not in the payments record');
});

test('manual fully paid wording uses the remaining ledger balance', function (): void {
    $booking = bookedCabin(['status' => BookingStatus::Confirmed]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
        'reference' => $booking->displayReference().'-D01',
    ]);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'FULLY_PAID',
            'reason' => 'Seen at the bank',
        ])
        ->assertOk();

    $history = ChangeHistory::query()->where('event', 'booking.status_changed')->latest('id')->firstOrFail();
    expect($history->after['what'] ?? '')->toContain('marked manually — USD 23,940 not in the payments record');
});

test('allowed_transitions differ for lucia mateo and carolina on the same booking', function (): void {
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    $carolina = adminUser(['name' => 'Carolina M.']);
    $booking = bookedCabin(['actor' => $mateo, 'status' => BookingStatus::Confirmed]);

    $this->actingAs($lucia)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('allowed_transitions', []);

    $this->actingAs($mateo)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('allowed_transitions.0.to', 'FULLY_PAID')
        ->assertJsonPath('allowed_transitions.1.to', 'CANCELLED');

    $this->actingAs($carolina)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('allowed_transitions.0.to', 'FULLY_PAID')
        ->assertJsonPath('allowed_transitions.1.to', 'CANCELLED');
});
