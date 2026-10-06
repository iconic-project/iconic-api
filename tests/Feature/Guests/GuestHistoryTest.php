<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Guest;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('guest writes record history on the booking and redact sensitive values', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])
        ->assertCreated();

    $guest = Guest::query()->where('booking_id', $booking->id)->firstOrFail();

    $this->actingAs($actor)
        ->patchJson('/api/rms/guests/'.$guest->id, [
            'first_name' => 'Julia',
            'last_name' => 'Brandt',
            'passport_no' => 'C4F7K8Q1R',
            'medical_note' => 'penicillin',
        ])
        ->assertOk();

    $updated = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'guest.updated')
        ->latest('id')
        ->first();

    expect($updated)->not->toBeNull();
    expect($updated?->after['what'] ?? null)->toBe('Passenger updated — Julia Brandt: first name, surname, passport number, medical note');
    expect(json_encode($updated?->before))->not->toContain('C4F7K8Q1R');
    expect(json_encode($updated?->after))->not->toContain('C4F7K8Q1R');
    expect(json_encode($updated?->before))->not->toContain('penicillin');
    expect(json_encode($updated?->after))->not->toContain('penicillin');
    expect($updated?->after['passport_no'] ?? null)->toBe('[redacted]');
    expect($updated?->after['medical_note'] ?? null)->toBe('[redacted]');

    $added = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'guest.added')
        ->first();

    expect($added?->after['what'] ?? null)->toBe('Guest slot added (1 guests)');
});

test('guardian consent is its own entry and is not listed on guest.updated', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Leon',
            'last_name' => 'Brandt',
            'dob' => '2015-03-02',
        ])
        ->assertCreated();

    $guest = Guest::query()->where('booking_id', $booking->id)->firstOrFail();

    $this->actingAs($actor)
        ->patchJson('/api/rms/guests/'.$guest->id, [
            'guardian_name' => 'Markus Brandt',
            'guardian_relationship' => 'Father',
            'guardian_consented' => true,
        ])
        ->assertOk();

    $events = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->whereIn('event', ['guest.updated', 'guest.guardian_consented'])
        ->orderBy('id')
        ->get();

    expect($events->pluck('event')->all())->toBe(['guest.updated', 'guest.guardian_consented']);
    expect($events[0]->after['what'] ?? null)->toBe(
        'Passenger updated — Leon Brandt: guardian name, guardian relationship',
    );
    expect($events[1]->event)->toBe('guest.guardian_consented');
    expect($events[1]->after['what'] ?? null)->toBe('Guardian consent recorded — Leon Brandt');

    $this->actingAs($actor)
        ->patchJson('/api/rms/guests/'.$guest->id, [
            'guardian_consented' => false,
        ])
        ->assertOk();

    $cleared = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'guest.guardian_consented')
        ->orderByDesc('id')
        ->first();

    expect($cleared?->after['what'] ?? null)->toBe('Guardian consent cleared — Leon Brandt');
    expect(
        ChangeHistory::query()
            ->where('subject_id', $booking->id)
            ->where('event', 'guest.updated')
            ->count(),
    )->toBe(1);
});

test('consent alone does not write guest.updated', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/guests', [
            'first_name' => 'Leon',
            'last_name' => 'Brandt',
            'dob' => '2015-03-02',
            'guardian_name' => 'Markus Brandt',
        ])
        ->assertCreated();

    $guest = Guest::query()->where('booking_id', $booking->id)->firstOrFail();

    $this->actingAs($actor)
        ->patchJson('/api/rms/guests/'.$guest->id, [
            'guardian_consented' => true,
        ])
        ->assertOk();

    expect(
        ChangeHistory::query()
            ->where('subject_id', $booking->id)
            ->where('event', 'guest.updated')
            ->count(),
    )->toBe(0);
    expect(
        ChangeHistory::query()
            ->where('subject_id', $booking->id)
            ->where('event', 'guest.guardian_consented')
            ->count(),
    )->toBe(1);
});

test('removing an empty non-lead guest writes guest.removed', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])->assertCreated();
    $this->actingAs($actor)->postJson('/api/rms/bookings/'.$booking->id.'/guests', [])->assertCreated();

    $empty = Guest::query()->where('booking_id', $booking->id)->where('is_lead', false)->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson('/api/rms/guests/'.$empty->id)
        ->assertNoContent();

    $removed = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'guest.removed')
        ->first();

    expect($removed?->after['what'] ?? null)->toBe('Empty guest slot removed (1 guests)');
});
