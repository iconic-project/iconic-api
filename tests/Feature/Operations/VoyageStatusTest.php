<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Events\BookingStatusChanged;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Support\Alerts\AlertKeys;
use App\Support\Alerts\AlertSweep;
use App\Support\BusinessTime;
use App\Support\Operations\VoyageStatus;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
    Event::fake([BookingStatusChanged::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a fully paid booking boards on the galapagos departure date and not before midnight', function (): void {
    $departure = Departure::factory()->create(['date' => '2026-06-07']);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'status' => BookingStatus::FullyPaid,
        'reference' => 'ANK-2026-6107',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-06-07 05:30:00', 'UTC'));
    expect(BusinessTime::now()->toDateString())->toBe('2026-06-06');

    Artisan::call('iconic:voyage-status');
    expect($booking->fresh()?->status)->toBe(BookingStatus::FullyPaid);

    Carbon::setTestNow(Carbon::parse('2026-06-07 06:30:00', 'UTC'));
    expect(BusinessTime::now()->toDateString())->toBe('2026-06-07');

    Artisan::call('iconic:voyage-status');
    Artisan::call('iconic:voyage-status');

    expect($booking->fresh()?->status)->toBe(BookingStatus::OnBoard);
    expect(statusHistory($booking))->toHaveCount(1)
        ->and(statusHistory($booking)->first()?->actor_label)->toBe(VoyageStatus::ACTOR)
        ->and(statusHistory($booking)->first()?->after['status'] ?? null)->toBe(BookingStatus::OnBoard->value);

    Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event): bool {
        return $event->from === BookingStatus::FullyPaid && $event->to === BookingStatus::OnBoard;
    });
});

test('a charter follows the same departure-date move', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-07 18:00:00', 'UTC'));
    $departure = Departure::factory()->create(['date' => '2026-06-07']);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => null,
        'type' => BookingType::Charter,
        'status' => BookingStatus::FullyPaid,
        'reference' => 'ANK-2026-6112',
    ]);

    Artisan::call('iconic:voyage-status');

    expect($booking->fresh()?->status)->toBe(BookingStatus::OnBoard)
        ->and(statusHistory($booking)->first()?->actor_label)->toBe(VoyageStatus::ACTOR);
});

test('a missed stretch catches up to completed in one run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-01-20 18:00:00', 'UTC'));
    $departure = Departure::factory()->create(['date' => '2026-01-04']);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'status' => BookingStatus::FullyPaid,
        'reference' => 'ANK-2026-0104',
    ]);

    Artisan::call('iconic:voyage-status');
    Artisan::call('iconic:voyage-status');

    $history = statusHistory($booking);

    expect($booking->fresh()?->status)->toBe(BookingStatus::Completed)
        ->and($history)->toHaveCount(2)
        ->and($history[0]->after['status'] ?? null)->toBe(BookingStatus::OnBoard->value)
        ->and($history[1]->after['status'] ?? null)->toBe(BookingStatus::Completed->value)
        ->and($history->every(fn (ChangeHistory $row): bool => $row->actor_label === VoyageStatus::ACTOR))->toBeTrue();

    Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event): bool {
        return $event->from === BookingStatus::FullyPaid && $event->to === BookingStatus::OnBoard;
    });
    Event::assertDispatched(BookingStatusChanged::class, function (BookingStatusChanged $event): bool {
        return $event->from === BookingStatus::OnBoard && $event->to === BookingStatus::Completed;
    });
});

test('confirmed and on hold agency bookings at departure are not moved', function (BookingStatus $status): void {
    Carbon::setTestNow(Carbon::parse('2026-06-07 18:00:00', 'UTC'));
    $departure = Departure::factory()->create(['date' => '2026-06-07']);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'status' => $status,
        'reference' => 'ANK-2026-'.$status->value,
    ]);

    Artisan::call('iconic:voyage-status');
    Artisan::call('iconic:voyage-status');

    $alert = Alert::query()->where('base_key', AlertKeys::confirmedAtDeparture($booking->id))->first();

    expect($booking->fresh()?->status)->toBe($status)
        ->and(statusHistory($booking))->toHaveCount(0)
        ->and(Alert::query()->where('kind', AlertKind::ConfirmedAtDeparture)->count())->toBe(1)
        ->and($alert?->sentence)->toContain($status->value);

    $booking->status = BookingStatus::FullyPaid;
    $booking->save();
    app(AlertSweep::class)->run();

    expect($alert?->fresh()?->resolved_at)->not->toBeNull()
        ->and($alert?->fresh()?->resolution)->toBe('the booking left CONFIRMED or ON_HOLD_AGENCY');
})->with([
    BookingStatus::Confirmed,
    BookingStatus::OnHoldAgency,
]);

/**
 * @return Collection<int, ChangeHistory>
 */
function statusHistory(Booking $booking): Collection
{
    return ChangeHistory::query()
        ->where('subject_type', $booking->getMorphClass())
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.status_changed')
        ->orderBy('id')
        ->get();
}
