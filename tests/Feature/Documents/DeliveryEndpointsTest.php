<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentLinkStatus;
use App\Mail\Documents\DocumentMail;
use App\Mail\Documents\PaymentLinkMail;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Delivery;
use App\Models\PaymentLink;
use App\Support\Documents\WireWarning;
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

function endpointSendBooking(?int $ownerId = null): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-12-03');

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6100',
        'owner_id' => $ownerId ?? adminUser()->id,
    ]);
}

test('staff can send an issued document and list deliveries', function (): void {
    $actor = adminUser();
    $booking = endpointSendBooking($actor->id);
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, actor: $actor);

    $sent = $this->actingAs($actor)
        ->postJson('/api/rms/documents/'.$document->id.'/send')
        ->assertCreated()
        ->assertJsonPath('kind', 'INVOICE')
        ->assertJsonPath('status', DeliveryStatus::Queued->value)
        ->assertJsonPath('triggered_by', 'USER')
        ->json();

    expect($sent['to'])->toBe([$booking->contact->email]);
    expect($sent)->not->toHaveKey('idempotency_key');
    expect(Delivery::query()->find($sent['id'])?->status)->toBe(DeliveryStatus::Sent);

    Mail::assertSent(DocumentMail::class, 1);

    $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id.'/deliveries')
        ->assertOk()
        ->assertJsonPath('data.0.id', $sent['id']);
});

test('own-records blocks document send and allows reading the log', function (): void {
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    $booking = endpointSendBooking($mateo->id);
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, actor: $mateo);

    $this->actingAs($lucia)
        ->postJson('/api/rms/documents/'.$document->id.'/send')
        ->assertForbidden();

    $this->actingAs($lucia)
        ->getJson('/api/rms/bookings/'.$booking->id.'/deliveries')
        ->assertOk();

    $this->actingAs($mateo)
        ->postJson('/api/rms/documents/'.$document->id.'/send')
        ->assertCreated();
});

test('an open payment link can be emailed', function (): void {
    $actor = adminUser();
    $booking = endpointSendBooking($actor->id);
    $link = PaymentLink::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'amount' => 2660,
        'status' => PaymentLinkStatus::Open,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/payment-links/'.$link->id.'/send')
        ->assertCreated()
        ->assertJsonPath('kind', DeliveryKind::PaymentLink->value)
        ->assertJsonPath('status', DeliveryStatus::Queued->value);

    Mail::assertSent(PaymentLinkMail::class, 1);
    Mail::assertSent(PaymentLinkMail::class, function (PaymentLinkMail $mail) use ($booking): bool {
        $token = BookingAccessToken::query()->where('booking_id', $booking->id)->first();
        $html = $mail->render();

        return $token instanceof BookingAccessToken
            && str_contains($html, $token->page_url)
            && ! str_contains($html, 'buy.stripe.com');
    });
    expect(Delivery::query()->where('kind', DeliveryKind::PaymentLink)->first()?->status)
        ->toBe(DeliveryStatus::Sent);
    expect(BookingAccessToken::query()->where('booking_id', $booking->id)->count())->toBe(1);
});

test('a cancelled payment link cannot be emailed', function (): void {
    $actor = adminUser();
    $booking = endpointSendBooking($actor->id);
    $link = PaymentLink::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentLinkStatus::Cancelled,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/payment-links/'.$link->id.'/send')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('link');
});

test('payments.record is required to email a payment link or wire instructions', function (): void {
    $lucia = salesExecUser();
    $booking = endpointSendBooking($lucia->id);
    $link = PaymentLink::factory()->create([
        'booking_id' => $booking->id,
        'status' => PaymentLinkStatus::Open,
    ]);

    $this->actingAs($lucia)
        ->postJson('/api/rms/payment-links/'.$link->id.'/send')
        ->assertForbidden();

    $this->actingAs($lucia)
        ->postJson('/api/rms/bookings/'.$booking->id.'/wire-instructions/send')
        ->assertForbidden();
});

test('wire instructions send issues the document and warns when the bank is TBD', function (): void {
    $actor = adminUser();
    $booking = endpointSendBooking($actor->id);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings/'.$booking->id.'/wire-instructions/send')
        ->assertCreated()
        ->assertJsonPath('kind', DeliveryKind::WireInstructions->value)
        ->assertJsonPath('status', DeliveryStatus::Queued->value)
        ->assertJsonPath('warning', WireWarning::MESSAGE);

    expect($booking->documents()->where('kind', DocumentKind::WireInstructions)->count())->toBe(1);
    Mail::assertSent(DocumentMail::class, 1);
});
