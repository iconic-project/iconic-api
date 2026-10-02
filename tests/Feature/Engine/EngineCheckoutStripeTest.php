<?php

declare(strict_types=1);

use App\Actions\Checkout\FallBackOnlineDeposit;
use App\Enums\BookingStatus;
use App\Enums\CabinCategory;
use App\Enums\CheckoutPath;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\ConsentDocument;
use App\Enums\DocumentKind;
use App\Enums\HoldType;
use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Enums\PaymentMethod;
use App\Mail\Documents\DocumentMail;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\CheckoutSession;
use App\Models\Consent;
use App\Models\Document;
use App\Models\Offer;
use App\Models\Payment;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Stripe\FakeStripeGateway;
use App\Support\Payments\ReconciliationMatch;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    adminUser();
});

/**
 * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
 * @return array<string, mixed>
 */
function depositQuote(int $departureId, array $cabins, ?string $promo = null): array
{
    return test()->postJson('/api/engine/quote', [
        'departure_id' => $departureId,
        'cabins' => $cabins,
        'online_deposit' => true,
        'promo_code' => $promo,
    ])->assertOk()->json();
}

/**
 * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
 * @return array<string, mixed>
 */
function depositDeclarations(): array
{
    return [
        ConsentDocument::Terms->value,
        ConsentDocument::Cancellation->value,
        ConsentDocument::Privacy->value,
        ConsentDocument::Insurance->value,
    ];
}

function engineIconicPromo(): Offer
{
    return Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'name' => 'Iconic welcome',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Iconic welcome −10%',
    ]);
}

/**
 * @param  array<string, mixed>  $event
 */
function postEngineStripeEvent(array $event): void
{
    $signed = FakeStripeGateway::signedEvent($event);

    test()->call(
        'POST',
        '/api/stripe/webhook',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signed['signature'],
        ],
        $signed['payload'],
    )->assertOk();
}

test('pay deposit opens a stripe checkout session and a stripe failure keeps the request', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $quote = depositQuote($departure->id, $created['cabins']);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $quote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'declarations' => depositDeclarations(),
        ]),
    )
        ->assertOk()
        ->assertJsonPath('path', CheckoutPath::PayDeposit->value)
        ->assertJsonStructure(['references', 'checkout_url']);

    $booking = Booking::query()->firstOrFail();
    $session = CheckoutSession::findByToken($created['token']);
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->online_deposit)->toBeTrue();
    expect($session?->stripe_checkout_session_id)->toStartWith('cs_test_');
    expect($session?->stripe_expires_at)->not->toBeNull();

    $gateway = app(FakeStripeGateway::class);
    expect($gateway->lastSuccessUrl)->toEndWith('/book/confirmation?session_id={CHECKOUT_SESSION_ID}');
    expect($gateway->lastCancelUrl)->toEndWith('/book/details?cancelled=1');

    $documents = Consent::query()->where('booking_id', $booking->id)->pluck('document');
    expect($documents)->toContain(
        ConsentDocument::Terms,
        ConsentDocument::Cancellation,
        ConsentDocument::Privacy,
        ConsentDocument::Insurance,
    );

    $gateway->failCheckout = true;
    $second = createCheckoutHold($departure, [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]]);
    $secondQuote = depositQuote($departure->id, $second['cabins']);

    $this->postJson(
        '/api/engine/checkout/'.$second['token'].'/submit',
        checkoutSubmitPayload($second['cabins'], (int) $secondQuote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'declarations' => depositDeclarations(),
        ]),
    )
        ->assertStatus(503)
        ->assertJsonPath('path', CheckoutPath::PayDeposit->value)
        ->assertJsonStructure(['references', 'message']);

    expect(Booking::query()->where('cabin_id', $departure->yacht->cabins->firstWhere('code', 'S2')?->id)->value('status'))
        ->toBe(BookingStatus::Requested);
});

test('settling an engine checkout confirms the booking and a replay does not double-pay', function (): void {
    Mail::fake();
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $quote = depositQuote($departure->id, $created['cabins']);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $quote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'declarations' => depositDeclarations(),
        ]),
    )->assertOk();

    $booking = Booking::query()->firstOrFail();
    $session = CheckoutSession::query()->firstOrFail();
    $deposit = $booking->depositAmount();
    $intent = 'pi_engine_001';

    $event = [
        'id' => 'evt_engine_pay',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $session->stripe_checkout_session_id,
                'payment_intent' => $intent,
                'amount_total' => $deposit * 100,
                'metadata' => [
                    'checkout_session_id' => (string) $session->id,
                    'kind' => 'DEPOSIT',
                    'deposit_'.$booking->id => (string) $deposit,
                ],
            ],
        ],
    ];

    postEngineStripeEvent($event);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Confirmed);
    expect($booking->reference)->toStartWith('ANK-');
    expect(Payment::query()->where('gateway_id', $intent.'#'.$booking->id)->count())->toBe(1);

    $matched = ReconciliationMatch::settlementFor($intent);
    expect($matched?->amount)->toBe($deposit);
    expect($matched?->method)->toBe(PaymentMethod::StripeLink);

    $replay = $event;
    $replay['id'] = 'evt_engine_replay';
    postEngineStripeEvent($replay);

    expect(Payment::query()->count())->toBe(1);
    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);

    Mail::assertSent(DocumentMail::class);
    expect(Document::query()->where('booking_id', $booking->id)->pluck('kind'))
        ->toContain(DocumentKind::Invoice, DocumentKind::Summary, DocumentKind::Receipt);
});

test('fallback on stripe expired removes only the advantage and promo lines', function (): void {
    $departure = checkoutWestDeparture();
    engineIconicPromo();
    $created = createCheckoutHold($departure);
    $quote = depositQuote($departure->id, $created['cabins'], 'ICONIC10');

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $quote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'promo_code' => 'ICONIC10',
            'declarations' => depositDeclarations(),
        ]),
    )->assertOk();

    $booking = Booking::query()->firstOrFail();
    $before = collect($booking->price_lines);
    $session = CheckoutSession::query()->firstOrFail();

    $document = ratesDocument();
    $document['years'][0]['suite_pp'] = ((int) $document['years'][0]['suite_pp']) + 5000;
    $current = app(CurrentConfig::class)->version(ConfigKind::Rates);
    app(ConfigPublisher::class)->publish(
        ConfigKind::Rates,
        $document,
        $current->version,
        'FALLBACK-RATES',
        adminUser(),
    );

    postEngineStripeEvent([
        'id' => 'evt_engine_expired',
        'type' => 'checkout.session.expired',
        'data' => [
            'object' => [
                'id' => $session->stripe_checkout_session_id,
                'metadata' => [
                    'checkout_session_id' => (string) $session->id,
                ],
            ],
        ],
    ]);

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->online_deposit)->toBeFalse();
    expect($booking->claims->first()?->released_at)->toBeNull();
    expect($booking->claims->first()?->hold_type)->toBe(HoldType::Request);
    expect($booking->claims->first()?->kind)->toBe(ClaimKind::Hold);

    $after = collect($booking->price_lines);
    $beforeCodes = $before->pluck('code')->all();
    $afterCodes = $after->pluck('code')->all();

    expect($afterCodes)->not->toContain('online_deposit');
    expect($beforeCodes)->toContain('online_deposit', 'ICONIC10');
    expect($after->firstWhere('code', 'ICONIC10')['amount'] ?? null)
        ->not->toBe($before->firstWhere('code', 'ICONIC10')['amount'] ?? null);

    $unchanged = array_values(array_diff($beforeCodes, ['online_deposit', 'ICONIC10']));
    foreach ($unchanged as $code) {
        expect($after->firstWhere('code', $code)['amount'] ?? null)
            ->toBe($before->firstWhere('code', $code)['amount'] ?? null);
    }

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.updated')
        ->latest('id')
        ->first();

    expect($history?->after['what'] ?? null)->toBe(FallBackOnlineDeposit::HISTORY);
    expect($history?->after['online_deposit'] ?? null)->toBeFalse();
});

test('the minute command confirms a checkout stripe reports complete', function (): void {
    Mail::fake();
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $quote = depositQuote($departure->id, $created['cabins']);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $quote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'declarations' => depositDeclarations(),
        ]),
    )->assertOk();

    $booking = Booking::query()->firstOrFail();
    $session = CheckoutSession::query()->firstOrFail();
    $session->stripe_expires_at = now()->subMinute();
    $session->save();

    $gateway = app(FakeStripeGateway::class);
    $gateway->setCheckoutSessionStatus((string) $session->stripe_checkout_session_id, 'complete', 'pi_late_001');

    $this->artisan('engine:expire-stripe-checkouts')->assertSuccessful();

    expect($booking->fresh()?->online_deposit)->toBeTrue();
    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);

    postEngineStripeEvent([
        'id' => 'evt_late_complete',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => $session->stripe_checkout_session_id,
                'payment_intent' => 'pi_late_001',
                'amount_total' => $booking->depositAmount() * 100,
                'metadata' => [
                    'checkout_session_id' => (string) $session->id,
                    'kind' => 'DEPOSIT',
                    'deposit_'.$booking->id => (string) $booking->depositAmount(),
                ],
            ],
        ],
    ]);

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()?->reference)->toStartWith('ANK-');
});

test('replay-stripe-checkout settles an engine session exactly once when posted twice', function (): void {
    Mail::fake();
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $quote = depositQuote($departure->id, $created['cabins']);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $quote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'declarations' => depositDeclarations(),
        ]),
    )->assertOk();

    $booking = Booking::query()->firstOrFail();
    $reference = (string) $booking->request_reference;

    $this->artisan('iconic:replay-stripe-checkout', ['reference' => $reference])
        ->assertSuccessful();

    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    expect($booking->fresh()?->reference)->toStartWith('ANK-');

    $this->artisan('iconic:replay-stripe-checkout', ['reference' => $reference])
        ->assertSuccessful();

    expect(Payment::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

test('replay-stripe-checkout --expired removes the online advantage and keeps the request', function (): void {
    $departure = checkoutWestDeparture();
    $created = createCheckoutHold($departure);
    $quote = depositQuote($departure->id, $created['cabins']);

    $this->postJson(
        '/api/engine/checkout/'.$created['token'].'/submit',
        checkoutSubmitPayload($created['cabins'], (int) $quote['total'], [
            'path' => CheckoutPath::PayDeposit->value,
            'declarations' => depositDeclarations(),
        ]),
    )->assertOk();

    $booking = Booking::query()->firstOrFail();
    expect($booking->online_deposit)->toBeTrue();

    $this->artisan('iconic:replay-stripe-checkout', [
        'reference' => (string) $booking->request_reference,
        '--expired' => true,
    ])->assertSuccessful();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->online_deposit)->toBeFalse();

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'booking.updated')
        ->latest('id')
        ->first();

    expect($history?->after['what'] ?? null)->toBe(FallBackOnlineDeposit::HISTORY);
});

test('the stripe expiry command is scheduled every minute', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($scheduled): bool => str_contains((string) ($scheduled->command ?? ''), 'engine:expire-stripe-checkouts'),
    );

    expect($event)->not->toBeNull();
    expect($event?->expression)->toBe('* * * * *');
});
