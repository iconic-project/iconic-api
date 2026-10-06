<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\DocumentKind;
use App\Enums\DocumentPlanKind;
use App\Enums\DocumentPlanStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Delivery;
use App\Models\Payment;
use App\Support\Documents\DeliveryKey;
use App\Support\Documents\DocumentPlan;
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

function planCabin(array $overrides = []): Booking
{
    $departure = $overrides['departure'] ?? ReservationFixtures::anamaraDeparture('2028-11-05');
    unset($overrides['departure']);

    return Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S6')?->id,
        'status' => BookingStatus::PendingPayment,
        'reference' => 'ANK-2026-6401',
        'total' => 26600,
        'deposit_pct' => 10,
        ...$overrides,
    ]);
}

test('the plan lists waiting invoice rows and a scheduled questionnaire', function (): void {
    $booking = planCabin();
    $rows = app(DocumentPlan::class)->for($booking, adminUser());
    $byKind = collect($rows)->keyBy(fn ($row) => $row->kind->value);

    expect($byKind[DocumentPlanKind::Invoice->value]->status)->toBe(DocumentPlanStatus::Waiting);
    expect($byKind[DocumentPlanKind::Summary->value]->status)->toBe(DocumentPlanStatus::Waiting);
    expect($byKind[DocumentPlanKind::Questionnaire->value]->status)->toBe(DocumentPlanStatus::Waiting);
    expect($byKind[DocumentPlanKind::Questionnaire->value]->trigger)->toBe('T−45');
    expect($byKind[DocumentPlanKind::Voucher->value]->status)->toBe(DocumentPlanStatus::NotContracted);
    expect($byKind[DocumentPlanKind::FinalInvoice->value]->status)->toBe(DocumentPlanStatus::Waiting);
    expect(collect($rows)->firstWhere('kind', DocumentPlanKind::WireInstructions))->toBeNull();
});

test('BLOCKED is shown when the client has no email', function (): void {
    $booking = planCabin(['status' => BookingStatus::Confirmed]);
    $booking->contact->update(['email' => null]);
    $booking->billing_email = null;
    $booking->save();

    $rows = app(DocumentPlan::class)->for($booking->fresh() ?? $booking, adminUser());
    $invoice = collect($rows)->first(fn ($row) => $row->kind === DocumentPlanKind::Invoice);

    expect($invoice?->status)->toBe(DocumentPlanStatus::Blocked);
    expect($invoice?->recipient)->toContain('No email');
});

test('FAILED is shown when the last delivery failed', function (): void {
    $booking = planCabin(['status' => BookingStatus::Confirmed]);
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, system: true);
    Delivery::query()->create([
        'booking_id' => $booking->id,
        'document_id' => $document->id,
        'kind' => DeliveryKind::Invoice,
        'idempotency_key' => DeliveryKey::forDocument($document),
        'to' => ['guest@iconic.test'],
        'cc' => [],
        'subject' => 'Invoice',
        'status' => DeliveryStatus::Failed,
        'error' => 'SMTP rejected',
        'triggered_by' => DeliveryTriggeredBy::System,
    ]);

    $rows = app(DocumentPlan::class)->for($booking->fresh() ?? $booking, adminUser());
    $invoice = collect($rows)->first(fn ($row) => $row->kind === DocumentPlanKind::Invoice);

    expect($invoice?->status)->toBe(DocumentPlanStatus::Failed);
    expect($invoice?->error)->toBe('SMTP rejected');
    expect($invoice?->canResend)->toBeTrue();
});

test('wire instructions appear only once issued', function (): void {
    $booking = planCabin(['status' => BookingStatus::Confirmed]);
    $before = app(DocumentPlan::class)->for($booking, adminUser());
    expect(collect($before)->firstWhere('kind', DocumentPlanKind::WireInstructions))->toBeNull();

    app(PrepareIssueDocument::class)->handle($booking, DocumentKind::WireInstructions, system: true);

    $after = app(DocumentPlan::class)->for($booking->fresh() ?? $booking, adminUser());
    expect(collect($after)->firstWhere('kind', DocumentPlanKind::WireInstructions))->not->toBeNull();
});

test('reminders are NOT NEEDED once the cruise balance is paid', function (): void {
    $booking = planCabin(['status' => BookingStatus::FullyPaid]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 26600,
    ]);

    $rows = app(DocumentPlan::class)->for($booking->fresh() ?? $booking, adminUser());
    $reminder = collect($rows)->first(fn ($row) => $row->kind === DocumentPlanKind::Reminder);

    expect($reminder?->status)->toBe(DocumentPlanStatus::NotNeeded);
});

test('GET plan is authorised by BookingPolicy view', function (): void {
    $booking = planCabin();

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id.'/documents/plan')
        ->assertOk()
        ->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.booking_reference', $booking->displayReference())
        ->assertJsonPath('data.0.client', $booking->contact->name);
});
