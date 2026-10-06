<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\ReleaseReason;
use App\Events\AvailabilityChanged;
use App\Listeners\OfferWaitlistRooms;
use App\Mail\Waitlist\WaitlistOfferMail;
use App\Models\Delivery;
use App\Models\Room;
use App\Models\WaitlistEntry;
use App\Services\Inventory\ClaimService;
use App\Support\Stays\StayDates;
use App\Support\Waitlist\WaitlistOfferCopy;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
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

test('a freed night that does not cover the whole stay sends nothing, then one offer', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $stay = $departure->stayDates();
    $type = $departure->property->roomTypes()->where('code', 'STD')->firstOrFail();
    $rooms = $departure->property->rooms->filter(
        fn (Room $room): bool => $room->room_type_id === $type->id,
    );
    $holders = [];

    DB::transaction(function () use ($stay, $rooms, &$holders): void {
        foreach ($rooms as $room) {
            $holder = ClaimHolder::query()->create([
                'reference' => 'BLK-'.$room->code,
                'name' => $room->code,
            ]);
            app(ClaimService::class)->claim(StayDates::forNights($stay->checkIn(), 1), collect([$room]), $holder, ClaimKind::Block);
            $holders[] = $holder;
        }
    });

    $id = test()->actingAs(managerUser())
        ->postJson('/api/rms/waitlist', [
            'room_type_id' => $type->id,
            'check_in' => '2028-03-05',
            'check_out' => '2028-03-08',
            'client' => ['name' => 'Partial Guest', 'email' => 'partial-wait@iconic.test'],
            'adults' => 2,
            'children' => 0,
        ])
        ->assertCreated()
        ->json('id');

    app(OfferWaitlistRooms::class)->handle(new AvailabilityChanged((int) $departure->property_id, StayDates::forNights('2028-03-06', 1)));
    $this->artisan('iconic:waitlist-notify')->assertSuccessful();

    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->count())->toBe(0);

    DB::transaction(function () use ($holders): void {
        foreach ($holders as $holder) {
            app(ClaimService::class)->release($holder, ReleaseReason::Released);
        }
    });

    app(OfferWaitlistRooms::class)->handle(new AvailabilityChanged((int) $departure->property_id, StayDates::of('2028-03-05', '2028-03-08')));
    app(OfferWaitlistRooms::class)->handle(new AvailabilityChanged((int) $departure->property_id, StayDates::of('2028-03-05', '2028-03-08')));

    expect(Delivery::query()->where('kind', DeliveryKind::WaitlistOffer)->where('status', DeliveryStatus::Sent)->count())->toBe(1)
        ->and(WaitlistEntry::query()->findOrFail($id)->notified_at)->not->toBeNull();

    Mail::assertSent(WaitlistOfferMail::class, function (WaitlistOfferMail $mail): bool {
        return $mail->sentence === 'A STD is free — Sun 5 – Wed 8 Mar 2028'
            && str_contains($mail->stayUrl, 'check_in=2028-03-05')
            && str_contains($mail->stayUrl, 'check_out=2028-03-08');
    });
});

test('the offer sentence uses the room type and real weekdays', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $type = $departure->property->roomTypes()->where('code', 'STD')->firstOrFail();
    $entry = WaitlistEntry::factory()->create([
        'room_type_id' => $type->id,
        'check_in' => '2028-03-05',
        'check_out' => '2028-03-08',
    ]);

    expect(WaitlistOfferCopy::sentence($entry))->toBe('A STD is free — Sun 5 – Wed 8 Mar 2028');
    expect(WaitlistOfferCopy::range($entry))->toBe('Sun 5 – Wed 8 Mar 2028');

    $entry->check_in = '2028-03-30';
    $entry->check_out = '2028-04-02';
    $entry->save();

    expect(WaitlistOfferCopy::sentence($entry->fresh()))->toBe('A STD is free — Thu 30 Mar 2028 – Sun 2 Apr 2028');
});
