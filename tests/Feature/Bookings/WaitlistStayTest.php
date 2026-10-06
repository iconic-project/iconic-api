<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\ReleaseReason;
use App\Events\AvailabilityChanged;
use App\Listeners\OfferWaitlistRooms;
use App\Mail\Waitlist\WaitlistOfferMail;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\Room;
use App\Models\WaitlistEntry;
use App\Services\Inventory\ClaimService;
use App\Support\Stays\StayDates;
use App\Support\Waitlist\BackfillWaitlistStays;
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

test('a legacy departure row backfills onto the suite stay', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $contact = Contact::factory()->create();
    $owner = Contact::factory()->create();

    DB::table('waitlist_entries')->insert([
        [
            'departure_id' => $departure->id,
            'cabin_category' => 'SUITE',
            'contact_id' => $contact->id,
            'adults' => 2,
            'children' => 0,
            'source' => 'RMS',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'departure_id' => $departure->id,
            'cabin_category' => 'OWNER',
            'contact_id' => $owner->id,
            'adults' => 2,
            'children' => 0,
            'source' => 'RMS',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app(BackfillWaitlistStays::class)->handle();

    $stay = $departure->stayDates();
    $suite = WaitlistEntry::query()->where('contact_id', $contact->id)->firstOrFail();
    $owners = WaitlistEntry::query()->where('contact_id', $owner->id)->firstOrFail();

    expect($suite->roomType->code)->toBe('SUITE')
        ->and($suite->roomType->name)->toBe('Suite')
        ->and($suite->check_in->toDateString())->toBe($stay->checkIn()->toDateString())
        ->and($suite->check_out->toDateString())->toBe($stay->checkOut()->toDateString())
        ->and($owners->roomType->code)->toBe('OWNER')
        ->and($owners->roomType->name)->toBe("Owner's Suite")
        ->and(DB::table('waitlist_entries')->where('id', $suite->id)->value('departure_id'))->toBe($departure->id);
});

test('a freed night that does not cover the whole stay sends nothing, then one offer', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $stay = $departure->stayDates();
    $type = $departure->property->roomTypes()->where('code', 'SUITE')->firstOrFail();
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
        return $mail->sentence === 'A Suite is free — Sun 5 – Wed 8 Mar 2028'
            && str_contains($mail->stayUrl, 'check_in=2028-03-05')
            && str_contains($mail->stayUrl, 'check_out=2028-03-08');
    });
});

test('the offer sentence uses the room type and real weekdays', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $type = $departure->property->roomTypes()->where('code', 'SUITE')->firstOrFail();
    $entry = WaitlistEntry::factory()->create([
        'room_type_id' => $type->id,
        'check_in' => '2028-03-05',
        'check_out' => '2028-03-08',
    ]);

    expect(WaitlistOfferCopy::sentence($entry))->toBe('A Suite is free — Sun 5 – Wed 8 Mar 2028');
    expect(WaitlistOfferCopy::range($entry))->toBe('Sun 5 – Wed 8 Mar 2028');

    $entry->check_in = '2028-03-30';
    $entry->check_out = '2028-04-02';
    $entry->save();

    expect(WaitlistOfferCopy::sentence($entry->fresh()))->toBe('A Suite is free — Thu 30 Mar 2028 – Sun 2 Apr 2028');
});
