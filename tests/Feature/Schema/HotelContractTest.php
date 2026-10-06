<?php

declare(strict_types=1);

use App\Models\Archive\Departure as ArchivedDeparture;
use App\Models\Archive\Itinerary as ArchivedItinerary;
use Illuminate\Support\Facades\Schema;
use LogicException;

test('yacht tables are archived and the archive models are read-only', function (): void {
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

    expect(fn () => (new ArchivedDeparture)->save())->toThrow(LogicException::class);
    expect(fn () => (new ArchivedItinerary)->delete())->toThrow(LogicException::class);
});
