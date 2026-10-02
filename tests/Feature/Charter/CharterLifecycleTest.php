<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CharterEnquiryStatus;
use App\Enums\ConsentDocument;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Mail\Charter\CharterProposalMail;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\CharterEnquiry;
use App\Models\Consent;
use App\Models\CrmTask;
use App\Models\Document;
use App\Models\RefundRequest;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a proposal is accepted into a frozen charter booking and an old version cannot be accepted', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-11-05 15:00:00', 'UTC'));

    $departure = ReservationFixtures::anamaraDeparture('2028-03-05');
    $this->postJson('/api/engine/charter-enquiries', [
        'departure_id' => $departure->id,
        'guests' => 12,
        'contact' => [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-'.uniqid().'@iconic.test',
        ],
        'message' => 'The whole yacht, please.',
    ])->assertCreated();

    $enquiry = CharterEnquiry::query()->firstOrFail();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/charter-enquiries/'.$enquiry->id.'/proposal')
        ->assertCreated()
        ->assertJsonPath('kind', DocumentKind::CharterProposal->value)
        ->assertJsonPath('version', 1);

    $enquiry->refresh();
    expect($enquiry->status)->toBe(CharterEnquiryStatus::Quoted);

    $first = BookingAccessToken::query()->firstOrFail();
    $firstPlain = basename((string) parse_url($first->page_url, PHP_URL_PATH));

    $shown = $this->getJson('/api/engine/charter-proposal/'.$firstPlain)
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('expired', false)
        ->json('price.total');

    Mail::assertSent(CharterProposalMail::class, function (CharterProposalMail $mail) use ($first): bool {
        return str_contains($mail->pageUrl, basename((string) parse_url($first->page_url, PHP_URL_PATH)));
    });

    $this->actingAs(adminUser())
        ->postJson('/api/rms/charter-enquiries/'.$enquiry->id.'/proposal', [
            'reason' => 'Guest count confirmed',
        ])
        ->assertCreated()
        ->assertJsonPath('version', 2);

    $this->getJson('/api/engine/charter-proposal/'.$firstPlain)
        ->assertOk()
        ->assertJsonPath('version', 1);

    $this->postJson('/api/engine/charter-proposal/'.$firstPlain.'/accept', [
        'name' => 'Ada Lovelace',
        'terms' => true,
    ])->assertStatus(410);

    $second = BookingAccessToken::query()->orderByDesc('id')->firstOrFail();
    $secondPlain = basename((string) parse_url($second->page_url, PHP_URL_PATH));

    $accepted = $this->postJson('/api/engine/charter-proposal/'.$secondPlain.'/accept', [
        'name' => 'Ada Lovelace',
        'terms' => true,
    ])->assertOk();

    $booking = Booking::query()->where('reference', $accepted->json('booking_reference'))->firstOrFail();
    expect($booking->type)->toBe(BookingType::Charter);
    expect($booking->status)->toBe(BookingStatus::PendingPayment);
    expect($booking->total)->toBe($shown);
    expect($booking->deposit_due_on?->toDateString())->toBe('2027-11-12');
    expect($accepted->json('deposit_due_on'))->toBe('2027-11-12');

    $enquiry->refresh();
    expect($enquiry->status)->toBe(CharterEnquiryStatus::Accepted);
    expect($enquiry->accepted_name)->toBe('Ada Lovelace');
    expect($enquiry->booking_id)->toBe($booking->id);

    $consent = Consent::query()->where('booking_id', $booking->id)->where('document', ConsentDocument::CharterProposal)->firstOrFail();
    expect($consent->version)->toContain('v2');
    expect($consent->ip)->not->toBeNull();
    expect($consent->accepted_at)->not->toBeNull();

    $this->postJson('/api/engine/charter-proposal/'.$secondPlain.'/accept', [
        'name' => 'Ada Lovelace',
        'terms' => true,
    ])->assertStatus(409);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/charter-enquiries')
        ->assertOk()
        ->assertJsonFragment([
            'state' => 'accepted',
            'reference' => $booking->reference,
        ]);
});

test('declining a proposal records the reason and does not create a booking', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-19');
    $this->postJson('/api/engine/charter-enquiries', [
        'departure_id' => $departure->id,
        'guests' => 12,
        'contact' => [
            'name' => 'Decline Guest',
            'email' => 'decline-'.uniqid().'@iconic.test',
        ],
        'message' => 'Send the proposal.',
    ])->assertCreated();

    $enquiry = CharterEnquiry::query()->firstOrFail();
    $this->actingAs(adminUser())
        ->postJson('/api/rms/charter-enquiries/'.$enquiry->id.'/proposal')
        ->assertCreated();

    $plain = basename((string) parse_url((string) BookingAccessToken::query()->value('page_url'), PHP_URL_PATH));

    $this->postJson('/api/engine/charter-proposal/'.$plain.'/decline', [
        'reason' => 'Dates no longer work',
    ])->assertOk()->assertJsonPath('status', CharterEnquiryStatus::Declined->value);

    expect(Booking::query()->count())->toBe(0);
    expect($enquiry->refresh()->status)->toBe(CharterEnquiryStatus::Declined);
});

test('an expired proposal still opens and cannot be accepted', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-03-12');
    $this->postJson('/api/engine/charter-enquiries', [
        'departure_id' => $departure->id,
        'guests' => 12,
        'contact' => [
            'name' => 'Grace Hopper',
            'email' => 'grace-'.uniqid().'@iconic.test',
        ],
        'message' => 'A week in March.',
    ])->assertCreated();

    $enquiry = CharterEnquiry::query()->firstOrFail();
    $this->actingAs(adminUser())
        ->postJson('/api/rms/charter-enquiries/'.$enquiry->id.'/proposal')
        ->assertCreated();

    $token = BookingAccessToken::query()->firstOrFail();
    $token->expires_at = now()->subMinute();
    $token->save();
    $plain = basename((string) parse_url($token->page_url, PHP_URL_PATH));

    $this->getJson('/api/engine/charter-proposal/'.$plain)
        ->assertOk()
        ->assertJsonPath('expired', true);

    $this->postJson('/api/engine/charter-proposal/'.$plain.'/accept', [
        'name' => 'Grace Hopper',
        'terms' => true,
    ])->assertStatus(410);
});

test('a missed charter deposit raises one task and one warning and a payment closes both', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-11-05 15:00:00', 'UTC'));

    $departure = ReservationFixtures::anamaraDeparture('2028-04-02');
    $this->postJson('/api/engine/charter-enquiries', [
        'departure_id' => $departure->id,
        'guests' => 12,
        'contact' => [
            'name' => 'Deposit Guest',
            'email' => 'deposit-'.uniqid().'@iconic.test',
        ],
        'message' => 'Please send the proposal.',
    ])->assertCreated();

    $enquiry = CharterEnquiry::query()->firstOrFail();
    $this->actingAs(adminUser())
        ->postJson('/api/rms/charter-enquiries/'.$enquiry->id.'/proposal')
        ->assertCreated();

    $plain = basename((string) parse_url((string) BookingAccessToken::query()->value('page_url'), PHP_URL_PATH));
    $accepted = $this->postJson('/api/engine/charter-proposal/'.$plain.'/accept', [
        'name' => 'Deposit Guest',
        'terms' => true,
    ])->assertOk();

    $booking = Booking::query()->where('reference', $accepted->json('booking_reference'))->firstOrFail();

    Carbon::setTestNow(Carbon::parse('2027-11-13 15:00:00', 'UTC'));
    $this->artisan('iconic:charter-deposits')->assertSuccessful();
    $this->artisan('iconic:charter-deposits')->assertSuccessful();

    expect(CrmTask::query()->where('kind', TaskKind::CharterDeposit)->count())->toBe(1);
    expect(Alert::query()->where('kind', 'CHARTER_DEPOSIT_DUE')->whereNull('resolved_at')->count())->toBe(1);
    expect($booking->refresh()->status)->toBe(BookingStatus::PendingPayment);

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => $booking->depositAmount(),
        ])
        ->assertCreated()
        ->assertJsonPath('booking.status', BookingStatus::Confirmed->value);

    $this->artisan('iconic:charter-deposits')->assertSuccessful();

    expect(CrmTask::query()->where('kind', TaskKind::CharterDeposit)->firstOrFail()->status)->toBe(TaskStatus::AutoClosed);
    expect(Alert::query()->where('kind', 'CHARTER_DEPOSIT_DUE')->whereNull('resolved_at')->count())->toBe(0);
    expect($booking->refresh()->status)->not->toBe(BookingStatus::Cancelled);

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('iconic:charter-deposits');
    expect(BusinessTime::zone())->toBe('Pacific/Galapagos');
});

test('a charter cancellation freezes the charter bands and a cabin cancellation freezes the cabin bands', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-05-07');
    $this->postJson('/api/engine/charter-enquiries', [
        'departure_id' => $departure->id,
        'guests' => 12,
        'contact' => [
            'name' => 'Cancel Charter',
            'email' => 'cancel-charter-'.uniqid().'@iconic.test',
        ],
        'message' => 'Proposal first.',
    ])->assertCreated();

    $enquiry = CharterEnquiry::query()->firstOrFail();
    $this->actingAs(adminUser())
        ->postJson('/api/rms/charter-enquiries/'.$enquiry->id.'/proposal')
        ->assertCreated();

    $plain = basename((string) parse_url((string) BookingAccessToken::query()->value('page_url'), PHP_URL_PATH));
    $accepted = $this->postJson('/api/engine/charter-proposal/'.$plain.'/accept', [
        'name' => 'Cancel Charter',
        'terms' => true,
    ])->assertOk();
    $charter = Booking::query()->where('reference', $accepted->json('booking_reference'))->firstOrFail();

    $cabinDeparture = ReservationFixtures::anamaraDeparture('2028-05-14');
    $cabin = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($cabinDeparture))
        ->assertCreated();
    $cabinBooking = Booking::query()->where('reference', $cabin->json('bookings.0.reference'))->firstOrFail();

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/bookings/'.$charter->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => $charter->depositAmount(),
        ])
        ->assertCreated();

    $this->actingAs(externalFinanceUser())
        ->postJson('/api/rms/bookings/'.$cabinBooking->id.'/payments', [
            'kind' => PaymentKind::Deposit->value,
            'method' => PaymentMethod::CardStripe->value,
            'amount' => $cabinBooking->depositAmount(),
        ])
        ->assertCreated();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$charter->id.'/transition', [
            'to' => BookingStatus::Cancelled->value,
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$cabinBooking->id.'/transition', [
            'to' => BookingStatus::Cancelled->value,
            'reason' => 'Guest withdrew',
        ])
        ->assertOk();

    $charterRefund = RefundRequest::query()->where('booking_id', $charter->id)->firstOrFail();
    $cabinRefund = RefundRequest::query()->where('booking_id', $cabinBooking->id)->firstOrFail();

    expect($charterRefund->band_source)->toBe('CHARTER');
    expect($cabinRefund->band_source)->toBe('CABIN');
    expect($charterRefund->band_min_days)->toBe(120);
    expect($cabinRefund->band_min_days)->toBe(120);

    $frozenCharter = $charterRefund->penalty_pct;
    $frozenCabin = $cabinRefund->penalty_pct;

    $charterRefund->refresh();
    $cabinRefund->refresh();
    expect($charterRefund->penalty_pct)->toBe($frozenCharter);
    expect($cabinRefund->penalty_pct)->toBe($frozenCabin);
    expect(Document::query()->where('charter_enquiry_id', $enquiry->id)->where('kind', DocumentKind::CharterProposal)->count())->toBe(1);
});
