<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\ClaimKind;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\Permission;
use App\Enums\ReleaseReason;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Events\AvailabilityChanged;
use App\Listeners\OfferWaitlistCabins;
use App\Mail\Waitlist\WaitlistOfferMail;
use App\Models\Booking;
use App\Models\Cabin;
use App\Models\CabinClaim;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Departure;
use App\Models\WaitlistEntry;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

test('a cancelled booking notifies the queue once per free cabin and never holds one', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $actor = managerUser();
    $holders = blockSuitesExcept($departure, 'S1');

    $first = waitlistEntry($departure, 'First Guest', 'first-wait@iconic.test');
    $second = waitlistEntry($departure, 'Second Guest', 'second-wait@iconic.test');
    waitlistEntry($departure, 'Third Guest', 'third-wait@iconic.test');

    $bookingId = $this->actingAs($actor)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'client' => ['name' => 'Cabin Guest', 'email' => 'cabin-guest@iconic.test'],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $this->artisan('iconic:waitlist-notify')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->count())->toBe(0);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$bookingId.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();

    $claims = CabinClaim::query()->count();
    $openClaims = CabinClaim::query()->whereNull('released_at')->count();
    $statuses = Booking::query()->orderBy('id')->pluck('status')->map(fn (BookingStatus $status): string => $status->value)->all();

    $this->artisan('iconic:waitlist-notify')->assertSuccessful();
    $this->artisan('iconic:waitlist-notify')->assertSuccessful();
    app(OfferWaitlistCabins::class)->handle(new AvailabilityChanged([(int) $departure->id]));

    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->where('status', DeliveryStatus::Sent)->count())->toBe(1)
        ->and(WaitlistEntry::query()->findOrFail($first)->notified_by)->toBeNull()
        ->and(WaitlistEntry::query()->findOrFail($second)->notified_at)->toBeNull()
        ->and(CabinClaim::query()->count())->toBe($claims)
        ->and(CabinClaim::query()->whereNull('released_at')->count())->toBe($openClaims)
        ->and(Booking::query()->orderBy('id')->pluck('status')->map(fn (BookingStatus $status): string => $status->value)->all())->toBe($statuses);

    Mail::assertSent(WaitlistOfferMail::class, function (WaitlistOfferMail $mail): bool {
        return str_contains($mail->sentence, 'nothing is held')
            && str_contains($mail->departureUrl, 'departure=');
    });

    $holder = $holders[array_key_first($holders)];
    DB::transaction(function () use ($holder): void {
        app(ClaimService::class)->release($holder, ReleaseReason::Released);
    });

    $this->artisan('iconic:waitlist-notify')->assertSuccessful();

    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->where('status', DeliveryStatus::Sent)->count())->toBe(2)
        ->and(WaitlistEntry::query()->findOrFail($second)->notified_at)->not->toBeNull();

    $claims = CabinClaim::query()->count();
    $openClaims = CabinClaim::query()->whereNull('released_at')->count();
    $statuses = Booking::query()->orderBy('id')->pluck('status')->map(fn (BookingStatus $status): string => $status->value)->all();

    $this->artisan('iconic:waitlist-notify')->assertSuccessful();

    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->where('status', DeliveryStatus::Sent)->count())->toBe(2)
        ->and(CabinClaim::query()->count())->toBe($claims)
        ->and(CabinClaim::query()->whereNull('released_at')->count())->toBe($openClaims)
        ->and(Booking::query()->orderBy('id')->pluck('status')->map(fn (BookingStatus $status): string => $status->value)->all())->toBe($statuses)
        ->and(CrmTask::query()->where('kind', TaskKind::WaitlistFollowUp)->count())->toBe(2);

    $task = CrmTask::query()->where('idempotency_key', 'waitlist-follow-up:'.$first)->firstOrFail();
    expect($task->owner_id)->toBeNull()
        ->and($task->needs_permission)->toBe(Permission::BookingsCreate)
        ->and($task->status)->toBe(TaskStatus::Open);

    $this->actingAs($actor)
        ->getJson('/api/rms/waitlist?departure_id='.$departure->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $first)
        ->assertJsonPath('data.0.position', 1)
        ->assertJsonPath('data.0.auto_notified', true)
        ->assertJsonPath('data.1.auto_notified', true)
        ->assertJsonPath('data.2.position', 3)
        ->assertJsonPath('data.2.auto_notified', false);

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('iconic:waitlist-notify');
});

test('a missing address is one blocked delivery and removal closes the follow-up', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-12');
    $actor = managerUser();
    blockSuitesExcept($departure, 'S1');

    $silent = waitlistEntry($departure, 'No Address', null);
    $removed = waitlistEntry($departure, 'Already Gone', 'gone-wait@iconic.test');
    $kept = waitlistEntry($departure, 'Still Waiting', 'kept-wait@iconic.test');

    $this->actingAs($actor)
        ->postJson('/api/rms/waitlist/'.$removed.'/remove', ['reason' => 'No longer interested'])
        ->assertOk();

    $bookingId = $this->actingAs($actor)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'client' => ['name' => 'Other Guest', 'email' => 'other-guest@iconic.test'],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$bookingId.'/transition', [
            'to' => 'CANCELLED',
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();

    $this->artisan('iconic:waitlist-notify')->assertSuccessful();
    $this->artisan('iconic:waitlist-notify')->assertSuccessful();

    $blocked = Delivery::query()->where('idempotency_key', 'waitlist:'.$silent)->get();
    expect($blocked)->toHaveCount(1)
        ->and($blocked->first()?->status)->toBe(DeliveryStatus::Blocked)
        ->and($blocked->first()?->blocked_reason)->toBe('No email address on the contact')
        ->and(WaitlistEntry::query()->findOrFail($removed)->notified_at)->toBeNull()
        ->and(WaitlistEntry::query()->findOrFail($kept)->notified_at)->not->toBeNull()
        ->and(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->where('status', DeliveryStatus::Sent)->count())->toBe(1);

    $task = CrmTask::query()->where('idempotency_key', 'waitlist-follow-up:'.$kept)->firstOrFail();
    expect($task->status)->toBe(TaskStatus::Open);

    $this->actingAs($actor)
        ->postJson('/api/rms/waitlist/'.$kept.'/remove', ['reason' => 'Booked elsewhere'])
        ->assertOk();

    expect($task->fresh()?->status)->toBe(TaskStatus::AutoClosed);

    $this->artisan('iconic:waitlist-notify')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->count())->toBe(2);
});

/**
 * @return array<string, ClaimHolder>
 */
function blockSuitesExcept(Departure $departure, string $keep): array
{
    $holders = [];
    $cabins = $departure->yacht->cabins->filter(
        fn (Cabin $cabin): bool => $cabin->category === CabinCategory::Suite && $cabin->code !== $keep,
    );

    DB::transaction(function () use ($departure, $cabins, &$holders): void {
        foreach ($cabins as $cabin) {
            $holder = ClaimHolder::query()->create([
                'reference' => 'BLK-'.$cabin->code,
                'name' => $cabin->code,
            ]);
            app(ClaimService::class)->claim($departure, collect([$cabin]), $holder, ClaimKind::Block);
            $holders[$cabin->code] = $holder;
        }
    });

    return $holders;
}

function waitlistEntry(Departure $departure, string $name, ?string $email): int
{
    $id = test()->actingAs(managerUser())
        ->postJson('/api/rms/waitlist', [
            'departure_id' => $departure->id,
            'cabin_category' => CabinCategory::Suite->value,
            'client' => [
                'name' => $name,
                'email' => $email,
            ],
            'adults' => 2,
            'children' => 0,
        ])
        ->assertCreated()
        ->json('id');

    return (int) $id;
}
