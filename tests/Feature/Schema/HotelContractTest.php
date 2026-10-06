<?php

declare(strict_types=1);

use App\Models\Archive\Departure as ArchivedDeparture;
use App\Models\Archive\Itinerary as ArchivedItinerary;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Support\History\History;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

test('yacht tables are archived and the history subject is the archive model', function (): void {
    expect(Schema::hasTable('departures'))->toBeFalse();
    expect(Schema::hasTable('itineraries'))->toBeFalse();
    expect(Schema::hasTable('cabin_claims'))->toBeFalse();
    expect(Schema::hasTable('archive_departures'))->toBeTrue();
    expect(Schema::hasTable('archive_itineraries'))->toBeTrue();
    expect(Schema::hasTable('archive_cabin_claims'))->toBeTrue();
    expect(Schema::hasColumn('bookings', 'departure_id'))->toBeFalse();
    expect(Schema::hasColumn('bookings', 'back_to_back'))->toBeFalse();
    expect(Schema::hasColumn('bookings', 'png_collected'))->toBeFalse();
    expect(Schema::hasColumn('offers', 'cabin_types'))->toBeFalse();
    expect(Schema::hasColumn('room_night_claims', 'legacy_cabin_claim_id'))->toBeFalse();

    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'itinerary_id' => $itinerary->id,
    ]);

    DB::transaction(function () use ($departure): void {
        History::record($departure, 'departure.noted', after: ['note' => 'kept']);
    });

    $entry = ChangeHistory::query()
        ->where('subject_type', 'departure')
        ->where('subject_id', $departure->id)
        ->where('event', 'departure.noted')
        ->firstOrFail();

    expect($entry->subject)->toBeInstanceOf(ArchivedDeparture::class);
    expect($entry->subject->historyLabel())->toBe($departure->reference);

    $mateo = managerUser();
    $this->actingAs($mateo)
        ->getJson('/api/rms/departures/'.$departure->id.'/history')
        ->assertOk()
        ->assertJsonFragment(['event' => 'departure.noted']);

    expect(fn () => ArchivedDeparture::query()->whereKey($departure->id)->firstOrFail()->save())
        ->toThrow(LogicException::class);
    expect(fn () => ArchivedItinerary::query()->whereKey($itinerary->id)->firstOrFail()->delete())
        ->toThrow(LogicException::class);
});
