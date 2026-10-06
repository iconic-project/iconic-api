<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentDocument;
use App\Enums\ConsentSource;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Support\History\History;
use App\Support\Money;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function timelineBooking(Contact $contact, User $owner): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2027-12-19');

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-8801',
    ]);
}

test('the timeline merges every allowed source newest first and hides sensitive values', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['name' => 'Timeline Guest']);
    $booking = timelineBooking($contact, $actor);
    $property = $booking->room?->property;

    DB::transaction(function () use ($booking): void {
        History::record($booking, 'booking.created', after: [
            'what' => 'Reservation created for 2 adults',
            'passport_no' => 'X999999',
            'gateway_id' => 'pi_secret',
        ]);
        History::record($booking, 'booking.requested', after: [
            'request_reference' => 'REQ-1',
            'status' => BookingStatus::Requested->value,
        ]);
        History::record($booking, 'booking.status_changed', after: [
            'status' => BookingStatus::Confirmed->value,
            'what' => 'Status Requested → Confirmed',
        ]);
        History::record($booking, 'booking.status_changed', after: [
            'status' => BookingStatus::FullyPaid->value,
            'what' => 'Status Confirmed → Fully paid',
        ]);
        History::record($booking, 'booking.status_changed', after: [
            'status' => BookingStatus::Cancelled->value,
            'what' => 'Status Fully paid → Cancelled',
        ]);
        History::record($booking, 'booking.moved', after: [
            'reference' => $booking->reference,
            'status' => BookingStatus::Confirmed->value,
        ]);
        History::record($booking, 'booking.status_changed', after: [
            'status' => BookingStatus::PendingPayment->value,
            'what' => 'Should not appear',
        ]);
        History::record($booking, 'booking.released', after: [
            'what' => 'Released — must stay off the timeline',
        ]);
    });

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
        'gateway_id' => 'pi_should_not_leak',
        'created_at' => now()->addMinute(),
    ]);

    Delivery::factory()->create([
        'booking_id' => $booking->id,
        'kind' => DeliveryKind::Invoice,
        'status' => DeliveryStatus::Sent,
        'to' => ['guest@iconic.test'],
        'created_at' => now()->addMinutes(2),
    ]);

    Consent::factory()->create([
        'booking_id' => $booking->id,
        'document' => ConsentDocument::Terms,
        'version' => 'v2026.1',
        'source' => ConsentSource::Engine,
        'withdrawn' => false,
        'accepted_at' => now()->addMinutes(3),
    ]);

    BehaviouralEvent::factory()->create([
        'contact_id' => $contact->id,
        'name' => BehaviouralEventName::ViewProperty,
        'params' => ['property_code' => $property?->code],
        'occurred_at' => now()->addMinutes(4),
    ]);

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline')
        ->assertOk();

    assertNoSensitiveFields($response);

    $json = $response->json();
    expect($json)->not->toHaveKey('gateway_id');
    expect(json_encode($json))->not->toContain('pi_should_not_leak');
    expect(json_encode($json))->not->toContain('X999999');
    expect(json_encode($json))->not->toContain('Should not appear');
    expect(json_encode($json))->not->toContain('must stay off the timeline');

    $items = collect($response->json('data'));
    $kinds = $items->pluck('kind')->all();

    expect($kinds)->toContain('booking', 'payment', 'delivery', 'consent', 'behavioural');

    $titles = $items->where('kind', 'booking')->pluck('title')->all();
    expect($titles)->toContain('Created', 'Requested', 'Confirmed', 'Fully paid', 'Cancelled', 'Moved');

    $created = $items->first(fn (array $row): bool => $row['title'] === 'Created');
    expect($created['detail'])->toBe('Reservation created for 2 adults');
    expect($created['link']['type'])->toBe('booking');
    expect($created['link']['reference'])->toBe('ANK-2026-8801');

    $requested = $items->first(fn (array $row): bool => $row['title'] === 'Requested');
    expect($requested['detail'])->toBe('Booking requested');

    $moved = $items->first(fn (array $row): bool => $row['title'] === 'Moved');
    expect($moved['detail'])->toBe('Booking moved to a new departure');

    $payment = $items->firstWhere('kind', 'payment');
    expect($payment['detail'])->toBe('Deposit · '.Money::format(2660).' · Settled');

    $delivery = $items->firstWhere('kind', 'delivery');
    expect($delivery['detail'])->toContain('Sent');
    expect($delivery['detail'])->toContain('guest@iconic.test');

    $consent = $items->firstWhere('kind', 'consent');
    expect($consent['detail'])->toContain('accepted');
    expect($consent['detail'])->toContain('v2026.1');

    $event = $items->firstWhere('kind', 'behavioural');
    expect($event['title'])->toBe('view_itinerary');
    expect($event['detail'])->toBe($property?->name);
    expect($event['link'])->toBeNull();

    $times = $items->pluck('at')->all();
    $sorted = $times;
    rsort($sorted);
    expect($times)->toBe($sorted);
});

test('a merged contact timeline includes the loser booking and events', function (): void {
    $actor = managerUser();
    $survivor = Contact::factory()->create(['name' => 'Older', 'email' => 'older@iconic.test']);
    $loser = Contact::factory()->create(['name' => 'Newer', 'email' => 'newer@iconic.test']);
    $booking = timelineBooking($loser, $actor);

    DB::transaction(fn () => History::record($booking, 'booking.created', after: [
        'what' => 'Loser reservation created',
    ]));

    BehaviouralEvent::factory()->create([
        'contact_id' => $loser->id,
        'name' => BehaviouralEventName::PageView,
        'params' => ['page_path' => '/western-realm'],
        'occurred_at' => now()->addMinute(),
    ]);

    $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Same guest',
        ])
        ->assertOk();

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$survivor->id.'/timeline')
        ->assertOk();

    assertNoSensitiveFields($response);

    $items = collect($response->json('data'));
    expect($items->firstWhere('title', 'Created')['detail'])->toBe('Loser reservation created');
    expect($items->firstWhere('kind', 'behavioural')['detail'])->toBe('/western-realm');
    expect($items->firstWhere('kind', 'merge')['title'])->toBe('Merged');
    expect($items->firstWhere('kind', 'merge')['detail'])->toContain('Same guest');
});

test('the timeline paginates', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create();

    foreach (range(1, 51) as $index) {
        BehaviouralEvent::factory()->create([
            'contact_id' => $contact->id,
            'name' => BehaviouralEventName::PageView,
            'params' => ['page_path' => '/p/'.$index],
            'occurred_at' => now()->addMinutes($index),
        ]);
    }

    $first = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline?per_page=50')
        ->assertOk();

    expect($first->json('meta.total'))->toBe(51);
    expect($first->json('data'))->toHaveCount(50);
    expect($first->json('data.0.detail'))->toBe('/p/51');

    $second = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline?per_page=50&page=2')
        ->assertOk();

    expect($second->json('data'))->toHaveCount(1);
});

test('a user without panel.crm cannot read a timeline', function (): void {
    $role = Role::factory()->create(['permissions' => [Permission::PanelRms]]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $contact = Contact::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline')
        ->assertForbidden();
});
