<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Jobs\SendDeliveryJob;
use App\Models\Alert;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\Document;
use App\Support\Operations\DocumentCheck;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
});

test('a failed automatic send is re-queued once and a second failure stays failed', function (): void {
    Queue::fake();
    $booking = Booking::factory()->create(['reference' => 'ANK-2026-9001']);
    $invoice = issued($booking, DocumentKind::Invoice, 1);
    issued($booking, DocumentKind::WireInstructions, 1);
    $delivery = Delivery::factory()->create([
        'booking_id' => $booking->id,
        'document_id' => $invoice->id,
        'status' => DeliveryStatus::Failed,
        'error' => 'Graph rejection',
        'idempotency_key' => 'invoice:'.$invoice->id,
    ]);

    Artisan::call('iconic:document-check');

    expect($delivery->fresh()?->status)->toBe(DeliveryStatus::Queued)
        ->and(Document::query()->count())->toBe(2)
        ->and(ChangeHistory::query()->where('event', DocumentCheck::REQUEUED)->count())->toBe(1);
    Queue::assertPushed(SendDeliveryJob::class, 1);

    $delivery->refresh();
    $delivery->status = DeliveryStatus::Failed;
    $delivery->error = 'Graph rejection';
    $delivery->save();

    Artisan::call('iconic:document-check');

    expect($delivery->fresh()?->status)->toBe(DeliveryStatus::Failed)
        ->and(Document::query()->count())->toBe(2)
        ->and(ChangeHistory::query()->where('event', DocumentCheck::REQUEUED)->count())->toBe(1)
        ->and(Delivery::query()->count())->toBe(1);
    Queue::assertPushed(SendDeliveryJob::class, 1);
});

test('a document with no recipient raises delivery failed and does not issue another version', function (): void {
    $booking = Booking::factory()->create([
        'reference' => 'ANK-2026-9002',
        'contact_id' => Contact::factory()->withoutEmail()->create()->id,
    ]);
    issued($booking, DocumentKind::Invoice, 1);

    Artisan::call('iconic:document-check');

    expect(Document::query()->where('booking_id', $booking->id)->count())->toBe(1)
        ->and(Delivery::query()->where('booking_id', $booking->id)->where('status', DeliveryStatus::Blocked)->count())->toBe(1)
        ->and(Alert::query()->where('booking_id', $booking->id)->where('kind', AlertKind::DeliveryFailed)->whereNull('resolved_at')->exists())->toBeTrue();
});

function issued(Booking $booking, DocumentKind $kind, int $version): Document
{
    return Document::query()->create([
        'booking_id' => $booking->id,
        'kind' => $kind,
        'version' => $version,
        'number' => $kind->value.'-'.$version,
        'reason' => 'Issued',
        'snapshot' => ['total' => 26600],
        'file_path' => 'documents/'.$kind->value.'-'.$version.'.pdf',
        'file_sha256' => str_repeat('a', 64),
        'issued_at' => now(),
    ]);
}
