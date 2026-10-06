<?php

declare(strict_types=1);

use App\Actions\GuestExperience\RecordGuestPreferences;
use App\Enums\BookingStatus;
use App\Enums\PreferenceSource;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Models\Property;
use App\Models\Room;
use App\Support\BusinessTime;
use App\Support\Retention\RetentionWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function retentionCabin(string $checkIn): Booking
{
    $anchor = ReservationFixtures::anamaraDeparture($checkIn);
    $checkOut = CarbonImmutable::parse($checkIn)->addDays(7)->toDateString();

    return Booking::factory()->create([
        'room_id' => $anchor->property->rooms->firstWhere('code', 'S1')?->id,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => 7,
        'owner_id' => managerUser()->id,
        'status' => BookingStatus::CheckedOut,
    ]);
}

function retentionGuest(Booking $booking, array $fields = []): Guest
{
    return Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'passport_no' => 'C4F7K2L9M',
        'passport_expiry' => '2035-05-01',
        'medical_note' => 'penicillin',
        'dietary_note' => 'no shellfish',
        'accessibility_note' => 'needs rail',
        ...$fields,
    ]);
}

test('29 February 2028 plus 24 months no-overflow is 28 February 2030', function (): void {
    $return = CarbonImmutable::parse('2028-02-29');

    expect(RetentionWindow::passportEndsOn($return, 24)->toDateString())->toBe('2030-02-28');
    expect($return->addMonths(24)->toDateString())->toBe('2030-03-01');
});

test('passports are purged the day after the 29 February overflow boundary', function (): void {
    $booking = retentionCabin('2028-02-22');
    expect($booking->check_out->toDateString())->toBe('2028-02-29');

    $guest = retentionGuest($booking, [
        'medical_note' => null,
        'dietary_note' => null,
        'accessibility_note' => null,
    ]);

    $this->travelTo(CarbonImmutable::parse('2030-02-27 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    $guest->refresh();
    expect($guest->passport_no)->toBe('C4F7K2L9M');

    $this->travelTo(CarbonImmutable::parse('2030-02-28 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    $guest->refresh();
    expect($guest->passport_no)->toBe('C4F7K2L9M');

    $this->travelTo(CarbonImmutable::parse('2030-03-01 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    $guest->refresh();
    expect($guest->passport_no)->toBeNull();
    expect($guest->passport_expiry)->toBeNull();

    $entry = ChangeHistory::query()->where('event', 'retention.applied')->where('subject_id', $booking->id)->first();
    expect($entry)->not->toBeNull();
    expect($entry?->actor_label)->toBe('System');
    expect($entry?->after['passports_anonymised'] ?? null)->toBe(1);
    expect(json_encode($entry?->after))->not->toContain('C4F7K2L9M');
    expect(json_encode($entry?->context))->not->toContain('C4F7K2L9M');
    expect($entry?->context['what'] ?? null)->toBe(
        'Retention — passport data anonymised for 1 guests (24 months after check-out, B4)',
    );
});

test('notes are purged the day after the 90-day boundary', function (): void {
    $booking = retentionCabin('2028-02-22');
    $guest = retentionGuest($booking, [
        'passport_no' => null,
        'passport_expiry' => null,
    ]);

    $this->travelTo(CarbonImmutable::parse('2028-05-28 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    $guest->refresh();
    expect($guest->medical_note)->toBe('penicillin');

    $this->travelTo(CarbonImmutable::parse('2028-05-29 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    $guest->refresh();
    expect($guest->medical_note)->toBe('penicillin');

    $this->travelTo(CarbonImmutable::parse('2028-05-30 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    $guest->refresh();
    expect($guest->medical_note)->toBeNull();
    expect($guest->dietary_note)->toBeNull();
    expect($guest->accessibility_note)->toBeNull();

    $entry = ChangeHistory::query()->where('event', 'retention.applied')->where('subject_id', $booking->id)->first();
    expect($entry?->after['notes_purged'] ?? null)->toBe(1);
    expect(json_encode($entry?->after))->not->toContain('penicillin');
    expect($entry?->context['what'] ?? null)->toBe(
        'Retention — medical notes purged for 1 guests (90 days after check-out, B4)',
    );
});

test('a second run the same day writes nothing', function (): void {
    $booking = retentionCabin('2028-02-22');
    retentionGuest($booking);

    $this->travelTo(CarbonImmutable::parse('2030-03-01 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    Artisan::call('iconic:retention');

    expect(ChangeHistory::query()->where('event', 'retention.applied')->where('subject_id', $booking->id)->count())->toBe(1);
});

test('dry-run prints counts and writes nothing', function (): void {
    $booking = retentionCabin('2028-02-22');
    $guest = retentionGuest($booking);

    $this->travelTo(CarbonImmutable::parse('2030-03-01 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:retention', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would change');

    $guest->refresh();
    expect($guest->passport_no)->toBe('C4F7K2L9M');
    expect($guest->medical_note)->toBe('penicillin');
    expect(ChangeHistory::query()->where('event', 'retention.applied')->count())->toBe(0);
});

test('retention follows check-out, and a later check-out is left alone', function (): void {
    $property = Property::factory()->create();
    $room = Room::factory()->create(['property_id' => $property->id]);
    $early = Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $room->id,
        'check_in' => '2026-01-01',
        'check_out' => '2026-01-08',
        'nights' => 7,
        'status' => BookingStatus::CheckedOut,
        'owner_id' => managerUser()->id,
        'reference' => 'ANK-RET-EARLY',
    ]);
    $late = Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $room->id,
        'check_in' => '2028-06-01',
        'check_out' => '2028-06-08',
        'nights' => 7,
        'status' => BookingStatus::CheckedOut,
        'owner_id' => managerUser()->id,
        'reference' => 'ANK-RET-LATE',
    ]);
    $earlyGuest = retentionGuest($early, [
        'medical_note' => null,
        'dietary_note' => null,
        'accessibility_note' => null,
    ]);
    $lateGuest = retentionGuest($late, [
        'medical_note' => null,
        'dietary_note' => null,
        'accessibility_note' => null,
    ]);

    $this->travelTo(CarbonImmutable::parse('2028-01-08 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');
    expect($earlyGuest->refresh()->passport_no)->toBe('C4F7K2L9M');
    expect($lateGuest->refresh()->passport_no)->toBe('C4F7K2L9M');

    $this->travelTo(CarbonImmutable::parse('2028-01-09 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');

    expect($earlyGuest->refresh()->passport_no)->toBeNull()
        ->and($lateGuest->refresh()->passport_no)->toBe('C4F7K2L9M');
});

test('a soft-deleted booking is still purged', function (): void {
    $booking = retentionCabin('2028-02-22');
    $guest = retentionGuest($booking);
    $booking->delete();

    $this->travelTo(CarbonImmutable::parse('2030-03-01 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');

    $guest->refresh();
    expect($guest->passport_no)->toBeNull();
    expect(ChangeHistory::query()->where('event', 'retention.applied')->where('subject_id', $booking->id)->exists())->toBeTrue();
});

test('preferences are purged on the medical date and leave no answer text', function (): void {
    $booking = retentionCabin('2028-02-22');
    $guest = retentionGuest($booking, [
        'passport_no' => null,
        'passport_expiry' => null,
        'medical_note' => null,
        'dietary_note' => null,
        'accessibility_note' => null,
    ]);

    $diet = 'QX-DIET-KELP-91';
    $access = 'QX-ACCESS-RAMP-91';

    app(RecordGuestPreferences::class)->handle(
        $guest,
        ['diet' => $diet, 'access' => $access],
        PreferenceSource::GuestLink,
        true,
        actorLabel: 'Guest (self-service)',
    );

    $this->travelTo(CarbonImmutable::parse('2028-05-29 12:00:00', BusinessTime::zone()));
    Artisan::call('iconic:retention');

    $row = GuestPreference::query()->where('guest_id', $guest->id)->first();
    expect($row)->not->toBeNull();
    expect($row?->answers['diet'] ?? null)->toBe($diet);
    expect($row?->accessibility)->toBe($access);
    expect($row?->purged_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2028-05-30 12:00:00', BusinessTime::zone()));
    $this->artisan('iconic:retention', ['--dry-run' => true])->assertSuccessful();

    $row = GuestPreference::query()->where('guest_id', $guest->id)->first();
    expect($row?->answers['diet'] ?? null)->toBe($diet);
    expect($row?->purged_at)->toBeNull();

    Artisan::call('iconic:retention');

    $row = GuestPreference::query()->where('guest_id', $guest->id)->first();
    expect($row)->not->toBeNull();
    expect($row?->answers)->toBe([]);
    expect($row?->accessibility)->toBeNull();
    expect($row?->emergency_contact)->toBeNull();
    expect($row?->purged_at)->not->toBeNull();

    $stored = DB::table('guest_preferences')->where('guest_id', $guest->id)->get();
    $history = DB::table('change_history')->get();
    $blob = json_encode([$stored, $history]);

    expect($blob)->not->toContain($diet);
    expect($blob)->not->toContain($access);

    $entry = ChangeHistory::query()->where('event', 'retention.applied')->where('subject_id', $booking->id)->first();
    expect($entry?->after['preferences_purged'] ?? null)->toBe(1);
    expect($entry?->context['what'] ?? null)->toBe(
        'Retention — guest preferences purged for 1 rows (90 days after check-out, B4)',
    );
});

test('the retention command is scheduled daily in Galapagos time', function (): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(
        fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'iconic:retention'),
    );

    expect($event)->not->toBeNull();
    expect($event?->expression)->toBe('0 0 * * *');
    expect($event?->timezone)->toBe(BusinessTime::zone());
    expect($event?->withoutOverlapping)->toBeTrue();
});
