<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Http\Resources\Rms\ChangeHistoryResource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Support\Bookings\BackfillBookingStays;
use App\Support\History\History;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(InventorySeeder::class);
    adminUser();
    Booking::factory()->create();
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
        ->assertJsonPath('times.no_show_at', null);
});
