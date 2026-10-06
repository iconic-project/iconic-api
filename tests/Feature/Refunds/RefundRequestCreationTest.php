<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CommissionAccrualStatus;
use App\Enums\ConfigKind;
use App\Enums\MainChannel;
use App\Enums\OverdueDecision;
use App\Enums\RefundRequestStatus;
use App\Models\Agency;
use App\Models\ChangeHistory;
use App\Models\RefundRequest;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Support\Commissions\Accrual;
use App\Support\Payments\Ledger;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a confirmed booking cancelled 484 days out uses the 5 percent band on the total', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 12:00:00', 'Pacific/Galapagos'));
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = refundCabin([
        'departure' => $departure,
        'status' => BookingStatus::Confirmed,
        'paid' => 2660,
        'total' => 26600,
        'reference' => 'ANK-2026-0505',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Guest withdrew',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Cancelled->value)
        ->assertJsonPath('refund.status', RefundRequestStatus::Pending->value)
        ->assertJsonPath('refund.penalty_amount', 1330)
        ->assertJsonPath('refund.refund_due', 1330)
        ->assertJsonPath('refund.band_label', '≥120 days');

    $request = RefundRequest::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($request->days_before_arrival)->toBe(484);
    expect($request->band_min_days)->toBe(120);
    expect($request->penalty_pct)->toBe(5);
    expect($request->paid_at_cancellation)->toBe(2660);
    expect($request->due_by)->not->toBeNull();

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'refund.requested')
        ->latest('id')
        ->firstOrFail();
    expect($history->after['penalty_amount'] ?? null)->toBe(1330);
    expect($history->after['refund_due'] ?? null)->toBe(1330);
});

test('refund due is clamped at zero when the penalty exceeds what was paid', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-10-08 12:00:00', 'Pacific/Galapagos'));
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = refundCabin([
        'departure' => $departure,
        'status' => BookingStatus::Confirmed,
        'paid' => 2660,
        'total' => 26600,
        'cabin_code' => 'S2',
        'reference' => 'ANK-2026-0506',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Late cancel',
        ])
        ->assertOk()
        ->assertJsonPath('refund.penalty_amount', 26600)
        ->assertJsonPath('refund.refund_due', 0)
        ->assertJsonPath('refund.band_label', '0–89 days');
});

test('a cancellation with no payments creates no request', function (): void {
    $booking = refundCabin([
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-0507',
        'cabin_code' => 'S3',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Never paid',
        ])
        ->assertOk()
        ->assertJsonPath('refund', null);

    expect(RefundRequest::query()->where('booking_id', $booking->id)->exists())->toBeFalse();

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'refund.not_due')
        ->latest('id')
        ->firstOrFail();
    expect($history->after['what'] ?? '')->toBe('Nothing was paid, so nothing is owed.');
});

test('an ops-007 cancel queues a refund request', function (): void {
    $booking = overdueCabin(['reference' => 'ANK-2026-0508', 'cabin_code' => 'S4']);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/overdue-decision', [
            'decision' => OverdueDecision::Cancel->value,
            'reason' => 'No response after the due date',
        ])
        ->assertOk();

    $request = RefundRequest::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($request->status)->toBe(RefundRequestStatus::Pending);
    expect($request->paid_at_cancellation)->toBe($booking->depositAmount());
});

test('cancelling an agency booking derives commission CANCELLED without a second write', function (): void {
    $agency = Agency::factory()->create(['commission_pct' => 10]);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $booking = refundCabin([
        'departure' => $departure,
        'status' => BookingStatus::Confirmed,
        'paid' => 2660,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'main_channel' => MainChannel::B2BTravelAdvisor,
        'cabin_code' => 'S5',
        'reference' => 'ANK-2026-0509',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Agency withdrew',
        ])
        ->assertOk();

    $fresh = $booking->fresh() ?? $booking;
    expect(Accrual::status($fresh, app(CurrentConfig::class)->businessRules()))
        ->toBe(CommissionAccrualStatus::Cancelled);
    expect($fresh->commission_pct)->toBe(10);
});

test('changing cancellation bands afterwards does not change a queued request', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 12:00:00', 'Pacific/Galapagos'));
    $booking = refundCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2027-11-07'),
        'status' => BookingStatus::Confirmed,
        'paid' => 2660,
        'cabin_code' => 'S6',
        'reference' => 'ANK-2026-0514',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();

    $request = RefundRequest::query()->where('booking_id', $booking->id)->firstOrFail();
    expect($request->penalty_pct)->toBe(5);
    expect($request->penalty_amount)->toBe(1330);
    expect($request->refund_due)->toBe(1330);

    $document = businessRulesDocument();
    $document['cancellation']['bands'] = [
        ['min_days' => 120, 'penalty_pct' => 20],
        ['min_days' => 90, 'penalty_pct' => 80],
        ['min_days' => 0, 'penalty_pct' => 100],
    ];

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        $document,
        1,
        'TEST-BANDS',
        adminUser(),
    );
    app(CurrentConfig::class)->forget(ConfigKind::BusinessRules);

    $request->refresh();
    expect($request->penalty_pct)->toBe(5);
    expect($request->penalty_amount)->toBe(1330);
    expect($request->refund_due)->toBe(1330);
    expect($request->band_min_days)->toBe(120);
});

test('releasing a booking does not create a refund request', function (): void {
    $booking = refundCabin([
        'status' => BookingStatus::OnHoldAgency,
        'paid' => 2660,
        'cabin_code' => 'S7',
        'reference' => 'ANK-2026-0515',
    ]);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => 'RELEASED',
            'reason' => 'Released',
        ])
        ->assertOk();

    expect(RefundRequest::query()->where('booking_id', $booking->id)->exists())->toBeFalse();
    expect(Ledger::paid($booking->fresh() ?? $booking))->toBe(2660);
});
