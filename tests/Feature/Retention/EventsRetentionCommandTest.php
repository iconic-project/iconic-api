<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Models\BehaviouralEvent;
use App\Models\BehaviouralEventDaily;
use App\Models\Contact;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function agedEvent(string $occurredAt, ?int $contactId, string $path = '/itineraries/western-realm'): BehaviouralEvent
{
    return BehaviouralEvent::factory()->create([
        'contact_id' => $contactId,
        'name' => BehaviouralEventName::ViewItinerary,
        'params' => ['itinerary_code' => 'WEST', 'page_path' => $path],
        'occurred_at' => $occurredAt,
        'received_at' => $occurredAt,
    ]);
}

test('stitched events older than 24 months are rolled up and deleted', function (): void {
    $contact = Contact::factory()->create();
    $old = agedEvent(now()->subMonths(25)->toDateTimeString(), $contact->id);
    $kept = agedEvent(now()->subMonths(23)->toDateTimeString(), $contact->id);

    Artisan::call('iconic:events-retention');

    expect(BehaviouralEvent::query()->whereKey($old->id)->exists())->toBeFalse();
    expect(BehaviouralEvent::query()->whereKey($kept->id)->exists())->toBeTrue();
    expect((int) BehaviouralEventDaily::query()->where('name', BehaviouralEventName::ViewItinerary)->sum('count'))->toBe(1);
});

test('unstitched events older than 30 days are rolled up and deleted', function (): void {
    $old = agedEvent(now()->subDays(31)->toDateTimeString(), null);
    $kept = agedEvent(now()->subDays(29)->toDateTimeString(), null);

    Artisan::call('iconic:events-retention');

    expect(BehaviouralEvent::query()->whereKey($old->id)->exists())->toBeFalse();
    expect(BehaviouralEvent::query()->whereKey($kept->id)->exists())->toBeTrue();
    expect((int) BehaviouralEventDaily::query()->sum('count'))->toBe(1);
});

test('a stitched event 31 days old is kept', function (): void {
    $contact = Contact::factory()->create();
    $event = agedEvent(now()->subDays(31)->toDateTimeString(), $contact->id);

    Artisan::call('iconic:events-retention');

    expect(BehaviouralEvent::query()->whereKey($event->id)->exists())->toBeTrue();
    expect(BehaviouralEventDaily::query()->count())->toBe(0);
});

test('dry run writes nothing', function (): void {
    agedEvent(now()->subMonths(25)->toDateTimeString(), Contact::factory()->create()->id);
    agedEvent(now()->subDays(31)->toDateTimeString(), null);

    $this->artisan('iconic:events-retention', ['--dry-run' => true])
        ->assertSuccessful();

    expect(BehaviouralEvent::query()->count())->toBe(2);
    expect(BehaviouralEventDaily::query()->count())->toBe(0);
});

test('the events retention command is scheduled daily in Galapagos time', function (): void {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(
        fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'iconic:events-retention'),
    );

    expect($event)->not->toBeNull();
    expect($event?->expression)->toBe('0 0 * * *');
    expect($event?->timezone)->toBe(BusinessTime::zone());
    expect($event?->withoutOverlapping)->toBeTrue();
});
