<?php

declare(strict_types=1);

use App\Actions\Complete\IssueCompleteAccessToken;
use App\Actions\GuestExperience\SendQuestionnaires;
use App\Enums\BookingAccessTokenPurpose;
use App\Enums\BookingStatus;
use App\Enums\PreferenceSource;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\ChangeHistory;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Support\BusinessTime;
use App\Support\Complete\CompleteAccess;
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
    adminUser();
});

/**
 * @return array{booking: Booking, lead: Guest, companion: Guest, token: string}
 */
function questionnaireFixture(): array
{
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6408',
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

    test()->travelTo(CarbonImmutable::parse('2028-07-20 12:00:00', BusinessTime::zone()));
    app(SendQuestionnaires::class)->handle($booking);

    $row = BookingAccessToken::query()
        ->where('guest_id', $lead->id)
        ->where('purpose', BookingAccessTokenPurpose::Questionnaire)
        ->firstOrFail();
    $token = basename($row->page_url);

    return ['booking' => $booking, 'lead' => $lead, 'companion' => $companion, 'token' => $token];
}

test('the guest link returns questions and never echoes restricted answers', function (): void {
    $fixture = questionnaireFixture();

    $this->putJson('/api/engine/questionnaire/'.$fixture['token'].'/guests/'.$fixture['lead']->id, [
        'answers' => [
            'diet' => 'no shellfish',
            'access' => 'QX-ACCESS-RAMP-91',
            'breakfast' => 'Light',
        ],
    ])->assertOk()
        ->assertJsonPath('reference', 'ANK-2026-6408')
        ->assertJsonPath('check_in', '2028-09-03')
        ->assertJsonPath('check_out', $fixture['booking']->stay()->checkOut()->toDateString())
        ->assertJsonPath('property_name', $fixture['booking']->property->name)
        ->assertJsonPath('guests.0.first_name', 'Ada')
        ->assertJsonPath('guests.0.answers.diet', 'no shellfish')
        ->assertJsonPath('guests.0.answers.access', 'provided')
        ->assertJsonPath('guests.0.answers.emerg', '')
        ->assertJsonMissing(['last_name' => 'Lovelace']);

    expect(GuestPreference::query()->where('guest_id', $fixture['lead']->id)->first()?->accessibility)->toBe('QX-ACCESS-RAMP-91');

    $history = ChangeHistory::query()->where('event', 'guest.preferences_recorded')->first();
    expect($history?->after['keys'] ?? [])->toContain('access')
        ->and($history?->after['source'] ?? null)->toBe(PreferenceSource::GuestLink->value)
        ->and(json_encode($history?->toArray()))->not->toContain('QX-ACCESS-RAMP-91')
        ->and(json_encode($history?->toArray()))->not->toContain('no shellfish');

    $this->getJson('/api/engine/questionnaire/'.$fixture['token'])
        ->assertOk()
        ->assertJsonPath('guests.0.answers.access', 'provided')
        ->assertJsonMissingPath('guests.0.last_name');

    expect($this->getJson('/api/engine/questionnaire/'.$fixture['token'])->getContent())
        ->not->toContain('QX-ACCESS-RAMP-91');
});

test('an empty restricted answer on the guest link keeps the stored value', function (): void {
    $fixture = questionnaireFixture();
    $url = '/api/engine/questionnaire/'.$fixture['token'].'/guests/'.$fixture['lead']->id;

    $this->putJson($url, ['answers' => ['access' => 'QX-ACCESS-RAMP-91', 'diet' => 'kelp']])->assertOk();
    $this->putJson($url, ['answers' => ['access' => '', 'diet' => 'kelp']])->assertOk()
        ->assertJsonPath('guests.0.answers.access', 'provided');

    $latest = GuestPreference::query()->where('guest_id', $fixture['lead']->id)->orderByDesc('version')->first();
    expect($latest?->version)->toBe(2)
        ->and($latest?->accessibility)->toBe('QX-ACCESS-RAMP-91')
        ->and($latest?->source)->toBe(PreferenceSource::GuestLink);
});

test('a guest outside the token, an expired token and a complete token are not found', function (): void {
    $fixture = questionnaireFixture();
    $url = '/api/engine/questionnaire/'.$fixture['token'].'/guests/';

    $this->putJson($url.$fixture['companion']->id, ['answers' => ['diet' => 'fish']])
        ->assertNotFound();

    $this->putJson($url.'999999', ['answers' => ['diet' => 'fish']])->assertNotFound();

    $this->travelTo(CarbonImmutable::parse('2028-09-11 12:00:00', BusinessTime::zone()));
    $this->getJson('/api/engine/questionnaire/'.$fixture['token'])->assertNotFound();

    $this->travelTo(CarbonImmutable::parse('2028-07-20 12:00:00', BusinessTime::zone()));
    $complete = app(IssueCompleteAccessToken::class)->handle($fixture['booking']);
    $plain = basename($complete);
    $this->getJson('/api/engine/questionnaire/'.$plain)->assertNotFound();

    $this->getJson('/api/engine/complete/'.$fixture['token'])->assertNotFound()
        ->assertJsonPath('message', CompleteAccess::NOT_FOUND);
});

test('unknown keys and options that are not on the list are rejected', function (): void {
    $fixture = questionnaireFixture();
    $url = '/api/engine/questionnaire/'.$fixture['token'].'/guests/'.$fixture['lead']->id;

    $this->putJson($url, ['answers' => ['kosher' => 'yes']])->assertUnprocessable()
        ->assertJsonValidationErrors(['answers.kosher']);

    $this->putJson($url, ['answers' => ['breakfast' => 'Kosher']])->assertUnprocessable()
        ->assertJsonValidationErrors(['answers.breakfast']);

    $this->putJson($url, ['answers' => ['diet' => str_repeat('a', 501)]])->assertUnprocessable()
        ->assertJsonValidationErrors(['answers.diet']);
});
