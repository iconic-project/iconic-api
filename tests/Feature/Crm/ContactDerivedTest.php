<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentDocument;
use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Enums\ContactLifecycle;
use App\Enums\ContactSegment;
use App\Enums\ContactType;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function crmBooking(Contact $contact, string $date, BookingStatus $status, int $total = 26600, string $cabin = 'S1'): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date);

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', $cabin)?->id,
        'contact_id' => $contact->id,
        'status' => $status,
        'total' => $total,
        'owner_id' => adminUser()->id,
    ]);
}

function crmDerived(Contact $contact): Contact
{
    return Contact::query()->withDerived()->whereKey($contact->id)->firstOrFail();
}

test('lifetime value sums sold charges and dropping a cancelled booking moves the segment', function (): void {
    $contact = Contact::factory()->create();
    $confirmed = crmBooking($contact, '2027-11-07', BookingStatus::Confirmed, 15000, 'S1');
    crmBooking($contact, '2027-11-14', BookingStatus::FullyPaid, 12000, 'S1');
    crmBooking($contact, '2027-11-21', BookingStatus::Cancelled, 20000, 'S1');
    crmBooking($contact, '2027-11-28', BookingStatus::Requested, 26600, 'S1');

    $row = crmDerived($contact);
    expect((int) $row->lifetime_value)->toBe(27000);
    expect($row->segment)->toBe(ContactSegment::High->value);

    $confirmed->update(['status' => BookingStatus::Cancelled]);

    $after = crmDerived($contact);
    expect((int) $after->lifetime_value)->toBe(12000);
    expect($after->segment)->toBe(ContactSegment::Mid->value);
});

test('lifecycle covers each branch including date windows and precedence', function (): void {
    $today = BusinessTime::now();

    $prospect = Contact::factory()->create();
    expect(crmDerived($prospect)->lifecycle)->toBe(ContactLifecycle::Prospect->value);

    $onBoard = Contact::factory()->create();
    crmBooking($onBoard, $today->addDays(30)->toDateString(), BookingStatus::OnBoard);
    expect(crmDerived($onBoard)->lifecycle)->toBe(ContactLifecycle::Guest->value);

    $guestMidCruise = Contact::factory()->create();
    crmBooking(
        $guestMidCruise,
        $today->subDay()->toDateString(),
        BookingStatus::FullyPaid,
    );
    expect(crmDerived($guestMidCruise)->lifecycle)->toBe(ContactLifecycle::Guest->value);

    $booked = Contact::factory()->create();
    crmBooking($booked, $today->addDays(21)->toDateString(), BookingStatus::Confirmed);
    expect(crmDerived($booked)->lifecycle)->toBe(ContactLifecycle::Booked->value);

    $sql = Contact::factory()->create();
    crmBooking($sql, $today->addDays(28)->toDateString(), BookingStatus::Requested);
    expect(crmDerived($sql)->lifecycle)->toBe(ContactLifecycle::Sql->value);

    $past = Contact::factory()->create();
    crmBooking($past, $today->subDays(8)->toDateString(), BookingStatus::FullyPaid);
    expect(crmDerived($past)->lifecycle)->toBe(ContactLifecycle::PastGuest->value);

    crmBooking($past, $today->addDays(35)->toDateString(), BookingStatus::Requested, cabin: 'S2');
    expect(crmDerived($past)->lifecycle)->toBe(ContactLifecycle::Sql->value);

    $completed = Contact::factory()->create();
    crmBooking($completed, $today->subDays(40)->toDateString(), BookingStatus::Completed);
    expect(crmDerived($completed)->lifecycle)->toBe(ContactLifecycle::PastGuest->value);

    $identified = Contact::factory()->create([
        'engine_identified_at' => now(),
    ]);
    expect(crmDerived($identified)->lifecycle)->toBe(ContactLifecycle::Mql->value);

    $mql = Contact::factory()->create();
    $cancelled = crmBooking($mql, $today->addDays(14)->toDateString(), BookingStatus::Cancelled);
    Consent::factory()->create([
        'booking_id' => $cancelled->id,
        'document' => ConsentDocument::Marketing,
        'source' => ConsentSource::Engine,
        'withdrawn' => false,
    ]);
    $register = new ContactConsent;
    $register->contact_id = $mql->id;
    $register->purpose = ConsentPurpose::Marketing;
    $register->granted = true;
    $register->version = 'v1';
    $register->captured_at = now();
    $register->capture_point = ConsentCapturePoint::EngineForm;
    $register->save();
    expect(crmDerived($mql)->lifecycle)->toBe(ContactLifecycle::Mql->value);

    $agent = Contact::factory()->create([
        'type' => ContactType::TravelAgent,
        'email' => 'partner@agency.test',
    ]);
    Agency::factory()->create([
        'email' => 'partner@agency.test',
        'status' => AgencyStatus::Approved,
    ]);
    crmBooking($agent, $today->addDays(10)->toDateString(), BookingStatus::OnBoard);
    expect(crmDerived($agent)->lifecycle)->toBe(ContactLifecycle::Agent->value);

    $pendingAgent = Contact::factory()->create([
        'type' => ContactType::TravelAgent,
        'email' => 'pending@agency.test',
    ]);
    Agency::factory()->pending()->create(['email' => 'pending@agency.test']);
    expect(crmDerived($pendingAgent)->lifecycle)->toBe(ContactLifecycle::Prospect->value);
});
