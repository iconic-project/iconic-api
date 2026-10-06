<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Departure;
use App\Support\Alerts\AlertKeys;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('night audit raises front-desk alerts and writes no status', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-08 18:00:00', BusinessTime::zone()));
    $today = BusinessTime::now()->toDateString();

    $arrival = nightAuditBooking(BookingStatus::FullyPaid, $today, '2026-06-15');
    $late = nightAuditBooking(BookingStatus::InHouse, '2026-06-01', '2026-06-07');
    $leaving = nightAuditBooking(BookingStatus::InHouse, '2026-06-04', $today);
    $before = Booking::query()->pluck('status', 'id')->all();

    Artisan::call('iconic:night-audit');
    Artisan::call('iconic:night-audit');

    expect(Booking::query()->pluck('status', 'id')->all())->toBe($before);
    expect(Alert::query()->where('kind', AlertKind::ArrivalNotCheckedIn)->count())->toBe(1);
    expect(Alert::query()->where('base_key', AlertKeys::arrivalNotCheckedIn($arrival->id))->count())->toBe(1);
    expect(Alert::query()->where('base_key', AlertKeys::inHousePastCheckOut($late->id))->count())->toBe(1);
    expect(Alert::query()->where('base_key', AlertKeys::checkOutStillOpen($leaving->id))->count())->toBe(1);
    expect(CrmTask::query()->where('booking_id', $arrival->id)->count())->toBe(1);
    expect(CrmTask::query()->where('booking_id', $late->id)->count())->toBe(1);
    expect(CrmTask::query()->where('booking_id', $leaving->id)->count())->toBe(1);
});

test('the voyage-status alias changes no status', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-07 18:00:00', BusinessTime::zone()));
    $booking = nightAuditBooking(BookingStatus::FullyPaid, '2026-06-07', '2026-06-14');

    Artisan::call('iconic:voyage-status');

    expect(Artisan::output())->toContain('deprecated');
    expect($booking->fresh()?->status)->toBe(BookingStatus::FullyPaid);
    expect(Alert::query()->where('kind', AlertKind::ArrivalNotCheckedIn)->count())->toBe(1);
});

function nightAuditBooking(BookingStatus $status, string $checkIn, string $checkOut): Booking
{
    $departure = Departure::factory()->create(['date' => $checkIn]);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'status' => $status,
        'reference' => 'ANK-AUDIT-'.substr(sha1($checkIn.$status->value.uniqid()), 0, 8),
    ]);

    $booking->forceFill([
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'checked_in_at' => null,
    ])->save();

    return $booking->refresh();
}
