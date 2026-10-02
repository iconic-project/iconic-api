<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\ChangeHistory;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\ExtrasDocument;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function extrasCabin(?int $ownerId = null, BookingStatus $status = BookingStatus::Confirmed): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $ownerId ?? adminUser()->id,
        'status' => $status,
        'total' => 26600,
        'deposit_pct' => 10,
    ]);
}

test('an active extra is added with the catalogue rate and listed with the subtotal', function (): void {
    $actor = adminUser();
    $booking = extrasCabin($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('code', 'FLT')
        ->assertJsonPath('qty', 2)
        ->assertJsonPath('rate_usd', 420)
        ->assertJsonPath('amount', 840)
        ->assertJsonPath('name', 'Domestic flights GYE/UIO ↔ SCY (round-trip)');

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/extras')
        ->assertOk()
        ->assertJsonPath('extras_total', 840)
        ->assertJsonPath('data.0.code', 'FLT')
        ->assertJsonPath('png_collected', false)
        ->assertJsonPath('tct_collected', false)
        ->assertJsonPath('png_pending_count', 0)
        ->assertJsonPath('tct_pp', 20)
        ->assertJsonPath('extras_due_hours', 72);

    $entry = ChangeHistory::query()->where('event', 'extra.added')->latest('id')->first();
    expect($entry?->after['what'] ?? null)->toBe(
        'Extra added — Domestic flights GYE/UIO ↔ SCY (round-trip) × 2 @ USD 420',
    );
});

test('an on-request extra requires a rate and an inactive item cannot be added', function (): void {
    $actor = adminUser();
    $booking = extrasCabin($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'SPA',
            'qty' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rate_usd']);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'SPA',
            'qty' => 1,
            'rate_usd' => 180,
        ])
        ->assertCreated()
        ->assertJsonPath('rate_usd', 180);

    $document = extrasDocument();
    $document['items'][0]['active'] = false;

    $this->actingAs($actor)
        ->postJson('/api/rms/extras/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-RETIRE',
        ])
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('a booking extra keeps its name and rate after a catalogue republish', function (): void {
    $actor = adminUser();
    $booking = extrasCabin($actor->id);

    $id = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 2,
        ])
        ->assertCreated()
        ->json('id');

    $document = extrasDocument();
    $document['items'][0]['name'] = 'Domestic flights (updated)';
    $document['items'][0]['price_usd'] = 500;

    $this->actingAs($actor)
        ->postJson('/api/rms/extras/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-REPRICE',
        ])
        ->assertCreated();

    expect(ExtrasDocument::fromArray(app(CurrentConfig::class)->extras()->toArray())->find('FLT')?->priceUsd)->toBe(500);

    $extra = BookingExtra::query()->findOrFail($id);
    expect($extra->name)->toBe('Domestic flights GYE/UIO ↔ SCY (round-trip)');
    expect($extra->rate_usd)->toBe(420);
});

test('removing an extra deletes the row and writes history', function (): void {
    $actor = adminUser();
    $booking = extrasCabin($actor->id);

    $id = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'HPRE',
            'qty' => 1,
        ])
        ->json('id');

    $this->actingAs($actor)
        ->deleteJson('/api/rms/booking-extras/'.$id)
        ->assertNoContent();

    expect(BookingExtra::query()->find($id))->toBeNull();

    $entry = ChangeHistory::query()->where('event', 'extra.removed')->latest('id')->first();
    expect($entry?->before['code'] ?? null)->toBe('HPRE');
    expect($entry?->after['what'] ?? null)->toBe(
        'Extra removed — Pre-cruise hotel — San Cristóbal (1 night, double) × 1',
    );
});

test('extras cannot be added or removed on cancelled or released bookings', function (): void {
    $actor = adminUser();

    foreach ([BookingStatus::Cancelled, BookingStatus::CancelledPostpaid, BookingStatus::Released] as $status) {
        $booking = extrasCabin($actor->id, $status);

        $this->actingAs($actor)
            ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
                'code' => 'FLT',
                'qty' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['booking']);
    }

    $booking = extrasCabin($actor->id);
    $id = $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 1,
        ])
        ->json('id');

    $booking->status = BookingStatus::Cancelled;
    $booking->save();

    $this->actingAs($actor)
        ->deleteJson('/api/rms/booking-extras/'.$id)
        ->assertStatus(422);
});

test('own-records blocks another sales exec from adding an extra', function (): void {
    $owner = salesExecUser();
    $other = salesExecUser(['email' => 'other-sales@iconic.test']);
    $booking = extrasCabin($owner->id);

    $this->actingAs($other)
        ->postJson('/api/rms/bookings/'.$booking->id.'/extras', [
            'code' => 'FLT',
            'qty' => 1,
        ])
        ->assertForbidden();
});
