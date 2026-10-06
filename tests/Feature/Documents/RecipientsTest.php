<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Documents\SendDocument;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Guest;
use App\Support\Documents\Recipients;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

function recipientBooking(array $overrides = []): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S4')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-4100',
        ...$overrides,
    ]);
}

test('the client of record is the contact email', function (): void {
    $booking = recipientBooking();
    $set = app(Recipients::class)->resolve($booking, DeliveryKind::Invoice);

    expect($set->usable())->toBeTrue();
    expect($set->to)->toBe([$booking->contact->email]);
    expect($set->cc)->toBe([]);
});

test('billing email wins over the contact', function (): void {
    $booking = recipientBooking(['billing_email' => 'billing@guest.test']);
    $set = app(Recipients::class)->resolve($booking, DeliveryKind::Invoice);

    expect($set->to)->toBe(['billing@guest.test']);
});

test('a group booking goes to the coordinator', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $coordinator = Contact::factory()->create(['email' => 'coord@group.test']);
    $group = Group::factory()->create([
        'coordinator_contact_id' => $coordinator->id,
    ]);
    $booking = recipientBooking(['group_id' => $group->id]);

    $set = app(Recipients::class)->resolve($booking->fresh(['group.coordinator', 'contact']), DeliveryKind::Invoice);

    expect($set->to)->toBe(['coord@group.test']);
});

test('an agency booking copies the agency on invoices only', function (): void {
    $agency = Agency::factory()->create(['email' => 'desk@agency.test']);
    $booking = recipientBooking(['agency_id' => $agency->id]);

    $invoice = app(Recipients::class)->resolve($booking->fresh('agency'), DeliveryKind::Invoice);
    expect($invoice->cc)->toBe(['desk@agency.test']);

    $summary = app(Recipients::class)->resolve($booking->fresh('agency'), DeliveryKind::Summary);
    expect($summary->cc)->toBe([]);
});

test('the summary goes to the lead guest when they have an email', function (): void {
    $booking = recipientBooking();
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@guest.test',
    ]);

    $set = app(Recipients::class)->resolve($booking->fresh('guests'), DeliveryKind::Summary);

    expect($set->to)->toBe(['ada@guest.test']);
});

test('no usable address writes BLOCKED with the reason and sends nothing', function (): void {
    $contact = Contact::factory()->withoutEmail()->create();
    $booking = recipientBooking(['contact_id' => $contact->id]);
    $actor = adminUser();
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, actor: $actor);

    $delivery = app(SendDocument::class)->handle($booking, $document, $actor);

    expect($delivery->status)->toBe(DeliveryStatus::Blocked);
    expect($delivery->blocked_reason)->toBe('No email address for the client of record');
    expect($delivery->idempotency_key)->toEndWith(':blocked');
    Mail::assertNothingSent();
});
