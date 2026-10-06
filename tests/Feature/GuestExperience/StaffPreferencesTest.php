<?php

declare(strict_types=1);

use App\Actions\Manifests\IssueManifest;
use App\Enums\BookingStatus;
use App\Enums\ManifestKind;
use App\Enums\ManifestReason;
use App\Enums\Permission;
use App\Enums\PreferenceSource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Manifests\ManifestPassenger;
use App\Support\Manifests\ManifestRoster;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Storage::fake('manifests');
    Storage::fake('documents');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{departure: Departure, booking: Booking, guest: Guest}
 */
function experienceFixture(): array
{
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6410',
        'owner_id' => managerUser()->id,
    ]);
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'medical_note' => 'penicillin',
    ]);

    return ['departure' => $departure, 'booking' => $booking, 'guest' => $guest];
}

function recorderWithoutSensitive(): User
{
    $role = Role::factory()->create([
        'permissions' => [
            Permission::PanelRms,
            Permission::BookingsViewAll,
            Permission::GuestExperienceManage,
        ],
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

test('staff read and write preferences, and a second version keeps history without answer text', function (): void {
    $fixture = experienceFixture();
    $manager = managerUser();

    $this->actingAs($manager)
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => [
                'diet' => 'QX-DIET-KELP-91',
                'celebr' => 'anniversary',
                'intensity' => 'Low',
                'time' => 'Flexible',
                'access' => 'QX-ACCESS-RAMP-91',
                'emerg' => 'Sam +1 555',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.current.version', 1)
        ->assertJsonPath('data.current.source', PreferenceSource::Staff->value)
        ->assertJsonPath('data.current.accessibility', 'QX-ACCESS-RAMP-91')
        ->assertJsonPath('data.current.answers.diet', 'QX-DIET-KELP-91');

    $history = ChangeHistory::query()->where('event', 'guest.preferences_recorded')->first();
    expect($history?->after['keys'] ?? [])->toContain('diet')
        ->and($history?->after['version'] ?? null)->toBe(1)
        ->and(json_encode($history?->toArray()))->not->toContain('QX-DIET-KELP-91')
        ->and(json_encode($history?->toArray()))->not->toContain('QX-ACCESS-RAMP-91');

    $this->actingAs(salesExecUser())
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', ['answers' => ['diet' => 'other']])
        ->assertForbidden();

    $this->actingAs(recorderWithoutSensitive())
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => ['diet' => 'QX-DIET-KELP-91', 'access' => 'changed'],
        ])
        ->assertForbidden();

    expect(GuestPreference::query()->where('guest_id', $fixture['guest']->id)->orderByDesc('version')->first()?->accessibility)
        ->toBe('QX-ACCESS-RAMP-91');

    $this->actingAs(recorderWithoutSensitive())
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => ['diet' => 'still kelp'],
        ])
        ->assertOk()
        ->assertJsonMissingPath('data.current.accessibility')
        ->assertJsonPath('data.current.accessibility_provided', true)
        ->assertJsonPath('data.current.answers.diet', 'still kelp');

    expect(GuestPreference::query()->where('guest_id', $fixture['guest']->id)->orderByDesc('version')->first()?->accessibility)
        ->toBe('QX-ACCESS-RAMP-91');

    $this->actingAs($manager)
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => ['diet' => 'still kelp', 'access' => ''],
        ])
        ->assertOk()
        ->assertJsonPath('data.current.accessibility', null);

    expect(GuestPreference::query()->where('guest_id', $fixture['guest']->id)->orderByDesc('version')->first()?->accessibility)
        ->toBeNull();
});

test('the departure view hides restricted values without guests.view_sensitive', function (): void {
    $fixture = experienceFixture();
    $this->travelTo(CarbonImmutable::parse('2028-07-20 12:00:00', BusinessTime::zone()));

    $this->actingAs(managerUser())
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => [
                'diet' => 'kelp',
                'celebr' => 'anniversary',
                'intensity' => 'Low',
                'time' => 'Flexible',
                'access' => 'ramp',
            ],
        ])->assertOk();

    $open = $this->actingAs(salesExecUser())
        ->getJson('/api/rms/guest-experience?from=2028-09-03&to=2028-09-03')
        ->assertOk()
        ->assertJsonPath('data.kpis.guests', 1)
        ->assertJsonPath('data.kpis.answered', 1)
        ->assertJsonPath('data.kpis.celebrations', 1)
        ->assertJsonPath('data.kpis.accessibility_or_medical', 1)
        ->assertJsonPath('data.send_date', '2028-07-20')
        ->assertJsonPath('data.send_state', 'sent')
        ->assertJsonPath('data.guests.0.status', 'ANSWERED')
        ->assertJsonPath('data.guests.0.dietary', 'kelp')
        ->assertJsonPath('data.guests.0.activity', 'Low · Flexible')
        ->assertJsonPath('data.guests.0.accessibility_provided', true);

    expect($open->json('data.guests.0'))->not->toHaveKey('accessibility');

    $this->actingAs(managerUser())
        ->getJson('/api/rms/guest-experience?from=2028-09-03&to=2028-09-03')
        ->assertOk()
        ->assertJsonPath('data.guests.0.accessibility', 'ramp');
});

test('the brief includes accessibility only with the permission and the pdf is not stored', function (): void {
    $fixture = experienceFixture();
    $before = count(Storage::disk('manifests')->allFiles()) + count(Storage::disk('documents')->allFiles());

    $fixture['booking']->forceFill(['expected_arrival_time' => '16:30'])->save();

    $this->actingAs(managerUser())
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => ['diet' => 'kelp', 'celebr' => 'anniversary', 'access' => 'ramp', 'pillow' => 'Firm', 'first' => 'Yes'],
        ])->assertOk();

    $hidden = $this->actingAs(salesExecUser())
        ->get('/api/rms/guest-experience/arrivals?date=2028-09-03');
    $hidden->assertOk();
    expect($hidden->getContent())
        ->toContain('ARRIVALS BRIEF')
        ->toContain('Expected arrival')
        ->toContain('16:30')
        ->toContain('kelp')
        ->toContain('anniversary')
        ->toContain('Firm × 1')
        ->not->toContain('ramp')
        ->not->toContain('Accessibility requirements');

    $shown = $this->actingAs(managerUser())
        ->get('/api/rms/guest-experience/arrivals?date=2028-09-03');
    expect($shown->getContent())->toContain('Accessibility requirements')->toContain('ramp');

    $this->actingAs(salesExecUser())
        ->get('/api/rms/departures/'.$fixture['departure']->id.'/hotel-manager-brief')
        ->assertStatus(410);
    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/departures/'.$fixture['departure']->id.'/guest-experience')
        ->assertStatus(410);
    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/guest-experience/departures')
        ->assertStatus(410);

    $pdf = $this->actingAs(managerUser())
        ->get('/api/rms/guest-experience/arrivals?date=2028-09-03&format=pdf');
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();

    expect(count(Storage::disk('manifests')->allFiles()) + count(Storage::disk('documents')->allFiles()))->toBe($before);
    expect(ChangeHistory::query()->where('event', 'brief.printed')->count())->toBe(3);
    expect(ChangeHistory::query()->where('event', 'brief.printed')->get()->map(fn (ChangeHistory $row): string => json_encode($row->toArray()) ?: '')->implode(''))
        ->not->toContain('kelp')
        ->not->toContain('ramp');
});

test('a preference change is a passenger change on the next captain manifest', function (): void {
    $fixture = experienceFixture();
    $manager = managerUser();

    app(IssueManifest::class)->request($fixture['departure'], ManifestKind::Captain, $manager);

    $this->actingAs($manager)
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => [
                'diet' => 'kelp',
                'access' => 'ramp',
                'emerg' => 'Sam at home',
            ],
        ])->assertOk();

    $issued = app(IssueManifest::class)->request($fixture['departure'], ManifestKind::Captain, $manager);
    expect($issued['manifest']->reason)->toBe(ManifestReason::PassengerChange)
        ->and($issued['manifest']->version)->toBe(2);

    $passenger = ManifestRoster::passengers($fixture['departure'])->firstOrFail();
    expect($passenger->guest->currentPreference?->accessibility)->toBe('ramp')
        ->and(implode(' | ', $passenger->captainCells()))
        ->toContain('kelp')
        ->toContain('Sam at home')
        ->toContain('ramp');

    app(IssueManifest::class)->request($fixture['departure'], ManifestKind::Dpng, $manager);

    $this->actingAs($manager)
        ->putJson('/api/rms/guests/'.$fixture['guest']->id.'/preferences', [
            'answers' => ['diet' => 'kelp and fruit', 'access' => 'ramp', 'emerg' => 'Sam at home'],
        ])->assertOk();

    $again = app(IssueManifest::class)->request($fixture['departure'], ManifestKind::Dpng, $manager);
    expect($again['created'])->toBeFalse();

    expect(ManifestPassenger::captainHeaders())->toContain('Emergency contact');
});
