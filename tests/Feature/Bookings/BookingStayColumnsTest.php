<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\BackfillBookingStays;
use App\Support\BusinessHours;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Payments\CancellationPenalty;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoBookingsSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoBookingsSeeder::class);
});

test('yacht bookings keep the same clock after the stay backfill', function (): void {
    $rules = app(CurrentConfig::class)->businessRules();
    $hours = BusinessHours::fromDocument($rules);
    $requestedAt = CarbonImmutable::parse('2026-06-01 15:00:00', 'UTC');
    $today = '2026-07-01';

    $bookings = Booking::query()->with(['departure.itinerary', 'room', 'roomType'])->get();

    expect($bookings)->not->toBeEmpty();
    expect(Booking::query()->whereNull('check_in')->exists())->toBeFalse();

    foreach ($bookings as $booking) {
        $departure = $booking->departure;
        expect($departure)->not->toBeNull();

        if ($departure === null) {
            continue;
        }

        $oldCheckIn = $departure->date->toDateString();
        $oldCheckOut = $departure->returnDate()->toDateString();

        expect($booking->stay()->checkIn()->toDateString())->toBe($oldCheckIn)
            ->and($booking->stay()->checkOut()->toDateString())->toBe($oldCheckOut)
            ->and($booking->nights)->toBe($departure->stayDates()->nights())
            ->and($booking->property_id)->toBe($departure->property_id)
            ->and($booking->rate_plan_code)->toBeNull()
            ->and($booking->night_lines)->toBeNull()
            ->and($booking->tax_lines)->toBeNull()
            ->and($booking->deposit_due_on)->toBeNull()
            ->and($booking->balanceDueDate()->toDateString())->toBe(
                $departure->date->subDays($booking->balance_days)->toDateString(),
            );

        $oldDays = BusinessTime::calendarDaysBetween($today, $oldCheckIn);
        $newDays = BusinessTime::calendarDaysBetween($today, $booking->stay()->checkIn()->toDateString());
        $bands = $booking->type === BookingType::Charter ? $rules->charterBands : $rules->bands;

        expect(CancellationPenalty::bandFor($newDays, $bands))->toBe(CancellationPenalty::bandFor($oldDays, $bands));

        $fromDeparture = $hours->holdExpiry($requestedAt, $departure->date, $rules);
        $fromStay = $hours->holdExpiry($requestedAt, $booking->stay()->checkIn(), $rules);

        expect($fromStay->rule)->toBe($fromDeparture->rule)
            ->and($fromStay->expiresAt->equalTo($fromDeparture->expiresAt))->toBeTrue();
    }
});

test('a charter without a room takes the property first room type by sort', function (): void {
    $booking = Booking::query()->where('reference', 'ANK-2026-0012')->firstOrFail();
    $first = RoomType::query()
        ->where('property_id', $booking->property_id)
        ->orderBy('sort')
        ->orderBy('id')
        ->firstOrFail();

    expect($booking->room_id)->toBeNull()
        ->and($booking->type)->toBe(BookingType::Charter)
        ->and($booking->room_type_id)->toBe($first->id)
        ->and($first->code)->toBe('SUITE');
});

test('status rewrite renames rows and history presentation keeps the stored value', function (): void {
    $booking = Booking::query()->firstOrFail();

    DB::table('bookings')->where('id', $booking->id)->update(['status' => 'ON_BOARD']);

    $entry = DB::transaction(fn () => History::record($booking, 'booking.status_changed', [
        'status' => 'FULLY_PAID',
    ], [
        'status' => 'ON_BOARD',
        'what' => 'Status FULLY PAID → ON BOARD',
    ]));

    app(BackfillBookingStays::class)->statuses();

    expect($booking->fresh()?->status)->toBe(BookingStatus::InHouse);

    $stored = ChangeHistory::query()->findOrFail($entry->id);
    expect($stored->after['status'] ?? null)->toBe('ON_BOARD')
        ->and($stored->after['what'] ?? null)->toBe('Status FULLY PAID → ON BOARD');

    $json = (new ChangeHistoryResource($stored))->resolve();

    expect($json['after']['status'] ?? null)->toBe('IN_HOUSE')
        ->and($json['after']['what'] ?? null)->toBe('Status FULLY PAID → IN HOUSE');
});

test('the booking resource exposes the stay', function (): void {
    $booking = Booking::query()->with(['room', 'roomType'])->whereNotNull('room_id')->firstOrFail();

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('stay.check_in', $booking->check_in->toDateString())
        ->assertJsonPath('stay.check_out', $booking->check_out->toDateString())
        ->assertJsonPath('stay.nights', $booking->nights)
        ->assertJsonPath('room.id', $booking->room_id)
        ->assertJsonPath('room_type.id', $booking->room_type_id)
        ->assertJsonPath('rate_plan', null)
        ->assertJsonPath('night_lines', null)
        ->assertJsonPath('tax_lines', null)
        ->assertJsonPath('times.expected_arrival_time', null)
        ->assertJsonPath('times.checked_in_at', null)
        ->assertJsonPath('times.checked_out_at', null)
        ->assertJsonPath('times.no_show_at', null)
        ->assertJsonPath('departure.id', $booking->departure_id);
});
