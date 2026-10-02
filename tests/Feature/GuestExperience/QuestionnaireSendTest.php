<?php

declare(strict_types=1);

use App\Enums\BookingAccessTokenPurpose;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Mail\Documents\QuestionnaireMail;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Delivery;
use App\Models\Guest;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('at T-45 guests with email get their own link and the rest go to the lead, once each', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->yacht->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6302',
        'total' => 26600,
    ]);
    $lead = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);
    $companion = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Bea',
        'last_name' => 'Lovelace',
        'email' => null,
    ]);

    $this->travelTo(CarbonImmutable::parse('2028-07-19 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Questionnaire)->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2028-07-20 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:documents-due')->assertSuccessful();

    $own = Delivery::query()->where('idempotency_key', 'questionnaire:'.$booking->id.':'.$lead->id)->first();
    $shared = Delivery::query()->where('idempotency_key', 'questionnaire:'.$booking->id.':lead')->first();

    expect($own)->not->toBeNull()
        ->and($own?->kind)->toBe(DeliveryKind::Questionnaire)
        ->and($own?->status)->toBe(DeliveryStatus::Sent)
        ->and($own?->to)->toBe(['ada@example.com'])
        ->and($shared)->not->toBeNull()
        ->and($shared?->to)->toBe(['ada@example.com'])
        ->and(Delivery::query()->where('kind', DeliveryKind::Questionnaire)->count())->toBe(2);

    $ownToken = BookingAccessToken::query()->where('guest_id', $lead->id)->where('purpose', BookingAccessTokenPurpose::Questionnaire)->first();
    $leadToken = BookingAccessToken::query()->where('booking_id', $booking->id)->whereNull('guest_id')->where('purpose', BookingAccessTokenPurpose::Questionnaire)->first();

    expect($ownToken?->covered_guest_ids)->toBe([$lead->id])
        ->and($ownToken?->expires_at?->utc()->format('Y-m-d H:i:s'))->toBe(
            BusinessTime::dayEndUtc($departure->returnDate()->toDateString())->format('Y-m-d H:i:s'),
        )
        ->and($leadToken?->covered_guest_ids)->toBe([$companion->id]);

    Mail::assertSent(QuestionnaireMail::class, 2);

    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Questionnaire)->count())->toBe(2);
});

test('a dry run lists the questionnaire and writes nothing', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->yacht->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6303',
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);

    $this->travelTo(CarbonImmutable::parse('2028-07-20 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:documents-due', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('questionnaire for ANK-2026-6303');

    expect(Delivery::query()->where('kind', DeliveryKind::Questionnaire)->count())->toBe(0);
    expect(BookingAccessToken::query()->where('purpose', BookingAccessTokenPurpose::Questionnaire)->count())->toBe(0);
});
