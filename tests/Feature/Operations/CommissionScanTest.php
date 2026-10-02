<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\ChannelOfOrigin;
use App\Models\Agency;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Support\Alerts\AlertKeys;
use App\Support\Operations\CommissionScan;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
});

test('each listed trade channel with no agency raises one leakage alert and a wholesaler does not', function (): void {
    foreach (CommissionScan::tradeChannels() as $index => $channel) {
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'agency_id' => null,
            'channel_of_origin' => $channel,
            'reference' => 'ANK-2026-8'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
        ]);
    }

    $wholesaler = Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'agency_id' => null,
        'channel_of_origin' => ChannelOfOrigin::Wholesaler,
        'reference' => 'ANK-2026-8099',
    ]);

    Artisan::call('iconic:commission-scan');

    expect(Alert::query()->where('kind', AlertKind::CommissionLeakage)->count())->toBe(count(CommissionScan::tradeChannels()))
        ->and(Alert::query()->where('base_key', AlertKeys::leakTrade($wholesaler->id))->exists())->toBeFalse();

    $booking = Booking::query()->where('reference', 'ANK-2026-8000')->firstOrFail();
    $booking->agency_id = Agency::factory()->create()->id;
    $booking->save();

    Artisan::call('iconic:commission-scan');

    expect(Alert::query()->where('base_key', AlertKeys::leakTrade($booking->id))->whereNull('resolved_at')->exists())->toBeFalse()
        ->and(Alert::query()->where('base_key', AlertKeys::leakTrade($booking->id))->first()?->resolution)->toBe('the finding is gone');
});

test('a travel advisor request with no agency is its own finding', function (): void {
    $booking = Booking::factory()->create([
        'status' => BookingStatus::FullyPaid,
        'agency_id' => null,
        'channel_of_origin' => ChannelOfOrigin::Email,
        'reference' => 'ANK-2026-8100',
    ]);
    BookingRequest::factory()->create([
        'booking_id' => $booking->id,
        'travel_advisor' => true,
    ]);

    Artisan::call('iconic:commission-scan');

    expect(Alert::query()->where('base_key', AlertKeys::leakAdvisor($booking->id))->whereNull('resolved_at')->exists())->toBeTrue()
        ->and(Alert::query()->where('kind', AlertKind::CommissionLeakage)->count())->toBe(1);
});

test('an over-cap booking that is not held and not approved leaks, and a cancelled one does not', function (): void {
    $agency = Agency::factory()->create([
        'status' => AgencyStatus::Approved,
        'commission_pct' => 20,
        'payment_terms' => 'Net 30',
    ]);
    $cancelled = Booking::factory()->create([
        'status' => BookingStatus::Cancelled,
        'agency_id' => $agency->id,
        'commission_approved' => false,
        'reference' => 'ANK-2026-8201',
    ]);
    $open = Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'agency_id' => $agency->id,
        'commission_approved' => false,
        'reference' => 'ANK-2026-8202',
    ]);

    Artisan::call('iconic:commission-scan');

    expect(Alert::query()->where('base_key', AlertKeys::leakCap($cancelled->id))->exists())->toBeFalse()
        ->and(Alert::query()->where('base_key', AlertKeys::leakCap($open->id))->whereNull('resolved_at')->exists())->toBeTrue();
});

test('an approved agency with no payment terms raises one alert and resolves when terms appear', function (): void {
    $agency = Agency::factory()->create([
        'status' => AgencyStatus::Approved,
        'payment_terms' => '',
        'commission_pct' => 10,
    ]);

    Artisan::call('iconic:commission-scan');

    $alert = Alert::query()->where('base_key', AlertKeys::leakTerms($agency->id))->first();
    expect($alert?->resolved_at)->toBeNull();

    $agency->payment_terms = 'Net 30';
    $agency->save();
    Artisan::call('iconic:commission-scan');

    expect($alert?->fresh()?->resolution)->toBe('the finding is gone');
});
