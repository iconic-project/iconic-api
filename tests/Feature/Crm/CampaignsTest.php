<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\DocumentKind;
use App\Enums\OfferType;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Offer;
use App\Support\BusinessTime;
use Database\Seeders\ConfigSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->seed(ConfigSeeder::class);
});

test('campaigns measure sold bookings by offer code and utm', function (): void {
    $manager = managerUser();
    $offer = Offer::factory()->live()->create(['code' => 'EARLY500', 'name' => 'Early 500']);
    $gift = Offer::factory()->live()->create([
        'code' => 'GIFTDAY',
        'name' => 'Gift day',
        'type' => OfferType::Value,
        'value' => 0,
        'value_text' => 'A gift day on board',
    ]);
    $naked = Offer::factory()->live()->create(['code' => 'NAKED', 'name' => 'Uncovered']);
    $pending = Offer::factory()->pending()->create(['code' => 'SOON', 'name' => 'Soon']);
    $yesterday = BusinessTime::now()->subDay()->toDateString();
    Offer::factory()->live()->create([
        'code' => 'OLDWIN',
        'name' => 'Expired window',
        'booking_to' => $yesterday,
    ]);
    Offer::factory()->create(['code' => 'DRAFT1', 'name' => 'Draft']);

    $agency = Agency::factory()->create();
    $line = Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-CAMP-1',
        'total' => 26600,
        'agency_id' => $agency->id,
        'price_lines' => [
            ['code' => 'base', 'label' => '2 adults', 'amount' => 26600],
            ['code' => 'Early500', 'label' => 'Early', 'amount' => -500],
        ],
        'utm_first' => ['campaign' => 'Early-Bird'],
    ]);
    Booking::factory()->create([
        'status' => BookingStatus::FullyPaid,
        'reference' => 'ANK-CAMP-2',
        'total' => 10000,
        'promo_code' => 'early500',
        'price_lines' => [
            ['code' => 'base', 'label' => '2 adults', 'amount' => 10000],
        ],
        'utm_last' => ['campaign' => 'EARLY-BIRD'],
    ]);
    Booking::factory()->create([
        'status' => BookingStatus::Cancelled,
        'reference' => 'ANK-CAMP-X',
        'total' => 50000,
        'promo_code' => 'EARLY500',
        'utm_first' => ['campaign' => 'early-bird'],
    ]);
    Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-CAMP-3',
        'total' => 26600,
        'price_lines' => [
            ['code' => 'base', 'label' => '2 adults', 'amount' => 26600],
            ['code' => 'giftday', 'label' => 'Gift day', 'amount' => 0],
        ],
    ]);

    $this->actingAs(salesExecUser())->postJson('/api/crm/campaigns', [
        'name' => 'Early bird',
        'offer_id' => $offer->id,
        'utm_campaign' => 'early-bird',
    ])->assertForbidden();

    $this->actingAs(salesExecUser())->getJson('/api/crm/campaigns')->assertOk();

    $created = $this->actingAs($manager)->postJson('/api/crm/campaigns', [
        'name' => 'Early bird',
        'offer_id' => $offer->id,
        'utm_campaign' => '  Early-Bird  ',
        'audience' => 'Past enquirers',
        'media_spend' => 0,
    ])->assertCreated();

    $giftCampaign = $this->actingAs($manager)->postJson('/api/crm/campaigns', [
        'name' => 'Gift',
        'offer_id' => $gift->id,
    ])->assertCreated();

    $this->actingAs($manager)->postJson('/api/crm/campaigns', [
        'name' => 'Neither',
    ])->assertUnprocessable();

    $index = $this->actingAs($manager)->getJson('/api/crm/campaigns')->assertOk();
    assertNoSensitiveFields($index);
    expect($index->json('meta.notes.sends'))->toBe('Marketing email is not built yet');

    $early = collect($index->json('data'))->firstWhere('id', $created->json('id'));
    expect($early['utm_campaign'])->toBe('early-bird');
    expect($early['offer']['code'])->toBe('EARLY500');
    expect($early['redeemed'])->toBe(2);
    expect($early['revenue'])->toBe(36600);
    expect($early['attributed_first'])->toBe(['count' => 1, 'revenue' => 26600]);
    expect($early['attributed_last'])->toBe(['count' => 1, 'revenue' => 10000]);
    expect($early['trade'])->toBe(1);
    expect($early['roas'])->toBeNull();
    expect($early['sends'])->toBeNull();
    expect($early['clicks'])->toBeNull();

    $giftRow = collect($index->json('data'))->firstWhere('id', $giftCampaign->json('id'));
    expect($giftRow['redeemed'])->toBe(1);
    expect($giftRow['revenue'])->toBe(26600);

    $bookings = $this->actingAs($manager)->getJson('/api/crm/campaigns/'.$created->json('id').'/bookings')->assertOk();
    $first = collect($bookings->json('data'))->firstWhere('reference', 'ANK-CAMP-1');
    $second = collect($bookings->json('data'))->firstWhere('reference', 'ANK-CAMP-2');
    expect($first['measures'])->toBe(['redeemed' => true, 'first_touch' => true, 'last_touch' => false]);
    expect($first['charges_total'])->toBe(26600);
    expect($first['status'])->toBe(BookingStatus::Confirmed->value);
    expect($second['measures']['redeemed'])->toBeTrue();
    expect($second['measures']['last_touch'])->toBeTrue();
    expect(collect($bookings->json('data'))->pluck('reference'))->not->toContain('ANK-CAMP-X');

    $uncovered = $this->actingAs($manager)->getJson('/api/crm/campaigns/offers-without-campaign')->assertOk();
    $codes = collect($uncovered->json('data'))->pluck('code')->all();
    expect($codes)->toContain('NAKED', 'SOON');
    expect($codes)->not->toContain('EARLY500', 'OLDWIN', 'DRAFT1');

    $this->actingAs($manager)->patchJson('/api/crm/campaigns/'.$created->json('id'), [
        'media_spend' => 13300,
    ])->assertOk();

    $afterSpend = $this->actingAs($manager)->getJson('/api/crm/campaigns')->assertOk();
    $updated = collect($afterSpend->json('data'))->firstWhere('id', $created->json('id'));
    expect((float) $updated['roas'])->toBe(2.8);

    $history = ChangeHistory::query()->where('event', 'campaign.updated')->where('subject_id', $created->json('id'))->first();
    expect($history)->not->toBeNull();
    expect($history?->before['media_spend'] ?? null)->toBe(0);
    expect($history?->after['media_spend'] ?? null)->toBe(13300);

    $model = $this->actingAs($manager)->getJson('/api/crm/campaigns/attribution-model')->assertOk();
    expect($model->json('data'))->toHaveCount(5);
    expect($model->json('data.0.stored_on'))->toContain('utm_first');
    expect($model->json('conflict'))->toContain('trade attribution wins');

    $this->actingAs($manager)->postJson('/api/crm/campaigns/'.$created->json('id').'/archive')->assertOk()
        ->assertJsonPath('status', 'ARCHIVED');

    expect($line->reference)->toBe('ANK-CAMP-1');
});

test('the delivery log lists what the rms sent and does not resend it', function (): void {
    $manager = managerUser();
    $contact = Contact::factory()->create(['name' => 'Ada Lovelace']);
    $booking = Booking::factory()->create([
        'contact_id' => $contact->id,
        'reference' => 'ANK-DLV-1',
        'status' => BookingStatus::Confirmed,
    ]);
    $first = Document::query()->create([
        'booking_id' => $booking->id,
        'kind' => DocumentKind::Invoice,
        'version' => 1,
        'number' => 'INV-1',
        'reason' => 'Issued with the booking',
        'snapshot' => ['total' => 26600],
        'file_path' => 'documents/inv-1.pdf',
        'file_sha256' => str_repeat('a', 64),
        'issued_at' => now(),
    ]);
    Document::query()->create([
        'booking_id' => $booking->id,
        'kind' => DocumentKind::Invoice,
        'version' => 2,
        'number' => 'INV-2',
        'reason' => 'Reissued',
        'snapshot' => ['total' => 26600],
        'file_path' => 'documents/inv-2.pdf',
        'file_sha256' => str_repeat('b', 64),
        'issued_at' => now(),
    ]);

    $this->actingAs($manager);
    Delivery::factory()->create([
        'booking_id' => $booking->id,
        'document_id' => $first->id,
        'kind' => DeliveryKind::Invoice,
        'to' => ['secret-guest@iconic.test'],
        'cc' => ['secret-cc@iconic.test'],
        'status' => DeliveryStatus::Sent,
        'sent_at' => now(),
        'error' => "Mailbox full\nsecond line",
        'triggered_by' => DeliveryTriggeredBy::System,
    ]);
    Delivery::factory()->create([
        'booking_id' => $booking->id,
        'document_id' => null,
        'kind' => DeliveryKind::Reminder,
        'to' => ['secret-guest@iconic.test'],
        'status' => DeliveryStatus::Blocked,
        'blocked_reason' => 'No marketing consent',
        'triggered_by' => DeliveryTriggeredBy::User,
    ]);
    Delivery::factory()->create([
        'booking_id' => $booking->id,
        'kind' => DeliveryKind::PaymentLink,
        'to' => ['secret-guest@iconic.test'],
        'status' => DeliveryStatus::Failed,
        'error' => 'Gateway down',
        'triggered_by' => DeliveryTriggeredBy::System,
    ]);
    Delivery::factory()->create([
        'booking_id' => $booking->id,
        'kind' => DeliveryKind::PaymentLink,
        'to' => ['secret-guest@iconic.test'],
        'status' => DeliveryStatus::Queued,
        'created_at' => now()->subMinutes(20),
        'triggered_by' => DeliveryTriggeredBy::System,
    ]);

    $page = $this->actingAs($manager)->getJson('/api/crm/deliveries')->assertOk();
    assertNoSensitiveFields($page);
    expect($page->json('meta.notes.engagement'))->toBe('Opens and downloads are not tracked (LEG-002)');
    expect($page->getContent())->not->toContain('secret-guest@iconic.test');
    expect($page->getContent())->not->toContain('secret-cc@iconic.test');
    expect($page->getContent())->not->toContain('documents/inv-1.pdf');

    $invoice = collect($page->json('data'))->first(fn (array $row): bool => ($row['document']['version'] ?? null) === 1);
    expect($invoice['booking']['reference'])->toBe('ANK-DLV-1');
    expect($invoice['booking']['id'])->toBe($booking->id);
    expect($invoice['client'])->toBe('Ada Lovelace');
    expect($invoice['document']['label'])->toBe(DocumentKind::Invoice->label());
    expect($invoice['document']['reason'])->toBe('Issued with the booking');
    expect($invoice['channel'])->toBe('EMAIL');
    expect($invoice['recipient_count'])->toBe(1);
    expect($invoice['triggered_by'])->toBe('System');
    expect($invoice['superseded'])->toBeTrue();
    expect($invoice['detail'])->toBe('Mailbox full');
    expect($invoice['rms_path'])->toBe('/rms/operations/documents?booking='.$booking->id);

    $reminder = collect($page->json('data'))->firstWhere('delivery_kind', DeliveryKind::Reminder->value);
    expect($reminder['document'])->toBeNull();
    expect($reminder['delivery_kind_label'])->toBe('Balance reminder');
    expect($reminder['detail'])->toBe('No marketing consent');
    expect($reminder['triggered_by'])->toBe($manager->name);

    expect($page->json('meta.kpis.sent_today'))->toBe(1);
    expect($page->json('meta.kpis.failed'))->toBe(1);
    expect($page->json('meta.kpis.blocked'))->toBe(1);
    expect($page->json('meta.kpis.queued_over_15_minutes'))->toBe(1);

    $failed = $this->actingAs($manager)->getJson('/api/crm/deliveries?status=FAILED&booking=ANK-DLV-1&contact=Lovelace')->assertOk();
    expect($failed->json('data'))->toHaveCount(1);
    expect($failed->json('data.0.status'))->toBe('FAILED');
    expect($failed->json('meta.kpis.failed'))->toBe(1);

    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());
    expect($uris->contains(fn (string $uri): bool => str_contains($uri, 'api/crm') && str_contains($uri, 'documents')))->toBeFalse();
});
