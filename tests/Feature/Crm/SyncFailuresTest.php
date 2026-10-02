<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Documents\SendDocument;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Delivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('failed jobs and failed deliveries appear and retry', function (): void {
    Mail::fake();
    Queue::fake();

    $admin = adminUser();
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Listeners\\SendOnPaymentSettled',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        ]),
        'exception' => "RuntimeException: Stripe timeout\n#0 /app/Listener.php",
        'failed_at' => now(),
    ]);

    $departure = ReservationFixtures::anamaraDeparture('2028-11-12');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-7701',
        'billing_email' => 'guest@iconic.test',
    ]);
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Invoice, actor: $admin);
    $delivery = app(SendDocument::class)->handle($booking, $document, $admin);
    $delivery->status = DeliveryStatus::Failed;
    $delivery->error = "TransportException: mailbox unavailable\nnext line";
    $delivery->save();

    $list = $this->actingAs($admin)
        ->getJson('/api/crm/sync/failures')
        ->assertOk();

    assertNoSensitiveFields($list);

    $ids = collect($list->json('data'))->pluck('id')->all();
    expect($ids)->toContain('job:'.$uuid);
    expect($ids)->toContain('delivery:'.$delivery->id);

    $job = collect($list->json('data'))->firstWhere('id', 'job:'.$uuid);
    expect($job['name'])->toBe('App\\Listeners\\SendOnPaymentSettled');
    expect($job['detail'])->toBe('RuntimeException: Stripe timeout');

    $failedDelivery = collect($list->json('data'))->firstWhere('id', 'delivery:'.$delivery->id);
    expect($failedDelivery['name'])->toContain('ANK-2026-7701');
    expect($failedDelivery['detail'])->toBe('TransportException: mailbox unavailable');

    $this->actingAs($admin)
        ->postJson('/api/crm/sync/failures/job:'.$uuid.'/retry')
        ->assertOk()
        ->assertJsonPath('retried', true);

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

    $this->actingAs($admin)
        ->postJson('/api/crm/sync/failures/delivery:'.$delivery->id.'/retry')
        ->assertOk()
        ->assertJsonPath('retried', true);

    expect(Delivery::query()->count())->toBe(2);
    expect(Delivery::query()->where('kind', DeliveryKind::Invoice)->where('id', '!=', $delivery->id)->exists())->toBeTrue();
});

test('retry requires sync.retry', function (): void {
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Listeners\\SendOnPaymentSettled']),
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $this->actingAs(salesExecUser())
        ->postJson('/api/crm/sync/failures/job:'.$uuid.'/retry')
        ->assertForbidden();

    $role = Role::factory()->create(['permissions' => [Permission::PanelRms]]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/crm/sync/failures')
        ->assertForbidden();
});
