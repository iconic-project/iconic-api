<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\HoldRule;
use App\Enums\HoldType;
use App\Enums\PreferredChannel;
use App\Exceptions\CabinUnavailableException;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Services\Inventory\ClaimService;
use App\Support\HoldExpiry;
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

test('a request draws ANK-R, writes a HOLD and SLA, and leaves reference null', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $actor = managerUser();

    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'preferred_channel' => 'WHATSAPP',
            'notes' => 'Anniversary on board',
        ]),
        $actor,
    );

    expect($booking->reference)->toBeNull();
    expect($booking->request_reference)->toStartWith('ANK-R-');
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->bookingRequest?->preferred_channel)->toBe(PreferredChannel::Whatsapp);
    expect($booking->bookingRequest?->hold_rule)->toBe(HoldRule::LongLead);
    expect($booking->bookingRequest?->sla_due_at?->equalTo(
        $booking->bookingRequest?->submitted_at?->copy()->addHours(24),
    ))->toBeTrue();

    $claim = CabinClaim::query()->where('holder_id', $booking->id)->whereNull('released_at')->firstOrFail();
    expect($claim->kind)->toBe(ClaimKind::Hold);
    expect($claim->hold_type)->toBe(HoldType::Request);
    expect($claim->expires_at)->not->toBeNull();

    expect(ChangeHistory::query()->where('event', 'booking.requested')->where('subject_id', $booking->id)->count())->toBe(1);
});

test('a near-term departure uses 48 business hours and a long-lead uses 5 business days', function (): void {
    $near = ReservationFixtures::anamaraDeparture('2027-01-03');
    $far = ReservationFixtures::anamaraDeparture('2027-11-07');
    $actor = managerUser();

    $nearBooking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($near, ['cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]]]),
        $actor,
    );
    $farBooking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($far, ['cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]]]),
        $actor,
    );

    expect($nearBooking->bookingRequest?->hold_rule->value)->toBe(HoldExpiry::NEAR_TERM);
    expect($farBooking->bookingRequest?->hold_rule->value)->toBe(HoldExpiry::LONG_LEAD);
});

test('a claim conflict creates nothing', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $cabin = $departure->property->cabins->firstWhere('code', 'S1');
    $holder = ClaimHolder::query()->create(['reference' => 'BLK', 'name' => 'Taken']);

    DB::transaction(function () use ($departure, $cabin, $holder): void {
        app(ClaimService::class)->claim($departure, collect([$cabin]), $holder, ClaimKind::Block);
    });

    expect(fn () => app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure),
        managerUser(),
    ))->toThrow(CabinUnavailableException::class);

    expect(Booking::query()->count())->toBe(0);
    expect(BookingRequest::query()->count())->toBe(0);
});
