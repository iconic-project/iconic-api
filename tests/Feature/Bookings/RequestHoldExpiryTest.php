<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\TransitionBooking;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\RoomNightClaim;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function seededRequest(string $cabin = 'S3'): Booking
{
    return app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload(ReservationFixtures::anamaraDeparture(), [
            'cabins' => [['cabin_code' => $cabin, 'adults' => 2, 'children' => 0]],
            'preferred_channel' => 'WHATSAPP',
        ]),
        managerUser(),
    );
}

test('the job expiring a request hold frees the cabin and keeps REQUESTED', function (): void {
    $booking = seededRequest();

    RoomNightClaim::query()
        ->where('holder_id', $booking->id)
        ->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);

    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    $booking->refresh()->load('bookingRequest');
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->bookingRequest?->hold_expired_at)->not->toBeNull();
    expect($booking->holdExpired())->toBeTrue();
    expect($booking->occupiesInventory())->toBeFalse();
    expect($booking->claims()->whereNull('released_at')->count())->toBe(0);

    $snapshot = app(Availability::class)->forDepartures(collect([$booking->departure]))[$booking->departure_id];
    $cell = collect($snapshot->cabins)->firstWhere('cabin.code', 'S3');
    expect($cell['state'])->toBe('FREE');

    expect(ChangeHistory::query()->where('event', 'request.hold_expired')->where('subject_id', $booking->id)->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'request.hold_expired')->value('actor_label'))->toBe('System');
    expect(ChangeHistory::query()->where('event', 'hold.expired')->where('subject_id', $booking->id)->count())->toBe(1);
    expect(ChangeHistory::query()->whereIn('event', ['booking.released', 'booking.deleted'])->count())->toBe(0);
});

test('pre-insert cleanup expires a request hold the same way', function (): void {
    $booking = seededRequest('S4');
    $departure = $booking->departure;
    $cabin = $booking->cabin;

    RoomNightClaim::query()
        ->where('holder_id', $booking->id)
        ->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);

    $other = ClaimHolder::query()->create(['reference' => 'NEW', 'name' => 'New']);

    DB::transaction(function () use ($departure, $cabin, $other): void {
        app(ClaimService::class)->claim($departure->stayDates(), collect([$cabin]), $other, ClaimKind::Booking);
    });

    $booking->refresh()->load('bookingRequest');
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->bookingRequest?->hold_expired_at)->not->toBeNull();
    expect(ChangeHistory::query()->where('event', 'request.hold_expired')->where('subject_id', $booking->id)->count())->toBe(1);
});

test('confirming after expiry re-claims or 409s when taken', function (): void {
    $booking = seededRequest('S5');
    RoomNightClaim::query()->where('holder_id', $booking->id)->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);
    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::PendingPayment->value)
        ->assertJsonPath('request_reference', $booking->request_reference)
        ->assertJsonPath('reference', null);

    expect($booking->fresh()->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())->toHaveCount(1);

    $taken = seededRequest('S6');
    RoomNightClaim::query()->where('holder_id', $taken->id)->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);
    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    $blocker = ClaimHolder::query()->create(['reference' => 'TKN', 'name' => 'Taken']);
    DB::transaction(function () use ($taken, $blocker): void {
        app(ClaimService::class)->claim($taken->departure->stayDates(), collect([$taken->cabin]), $blocker, ClaimKind::Booking);
    });

    $this->actingAs($taken->owner)
        ->postJson('/api/rms/requests/'.$taken->id.'/confirm')
        ->assertStatus(409)
        ->assertJsonPath('message', "The cabin was taken after this request's hold expired.");

    expect($taken->fresh()->status)->toBe(BookingStatus::Requested);
});

test('a hold expiring between the transition read and convert still claims or 409s', function (): void {
    $booking = seededRequest('S7');

    ClaimService::$beforeConvert = function () use ($booking): void {
        RoomNightClaim::query()
            ->where('holder_type', $booking->getMorphClass())
            ->where('holder_id', $booking->id)
            ->whereNull('released_at')
            ->update(['expires_at' => now()->subMinute()]);

        app(ClaimService::class)->releaseExpired();
    };

    app(TransitionBooking::class)->handle($booking, [
        'to' => BookingStatus::PendingPayment,
    ], $booking->owner);

    $fresh = $booking->fresh();
    expect($fresh?->status)->toBe(BookingStatus::PendingPayment);
    expect($fresh?->claims()->whereNull('released_at')->pluck('room_id')->unique())->toHaveCount(1);
    expect($fresh?->claims()->whereNull('released_at')->value('kind'))->toBe(ClaimKind::Booking);
});

test('HoldExpired sets hold_expired_at inside the same transaction (nights)', function (): void {
    $booking = seededRequest('S5');

    RoomNightClaim::query()
        ->where('holder_id', $booking->id)
        ->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);

    DB::beginTransaction();
    app(ClaimService::class)->releaseExpired();

    expect($booking->fresh()->bookingRequest?->hold_expired_at)->not->toBeNull();

    DB::rollBack();

    expect($booking->fresh()->bookingRequest?->hold_expired_at)->toBeNull();
});
