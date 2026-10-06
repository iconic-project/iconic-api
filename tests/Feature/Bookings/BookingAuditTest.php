<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\ClaimService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
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

test('the audit lists deleted and released rows newest first', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $admin = adminUser();

    $keep = $this->actingAs($admin)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure))
        ->assertCreated()
        ->json('bookings.0.id');

    $this->actingAs($admin)
        ->deleteJson('/api/rms/bookings/'.$keep, ['reason' => 'Wrong date']);

    $cabin = $departure->property->rooms->firstWhere('code', 'S2');
    $request = Booking::factory()->create([
        'status' => BookingStatus::Requested,
        'reference' => null,
        'request_reference' => 'ANK-R-2026-0042',
        'room_id' => $cabin?->id,
        'owner_id' => $admin->id,
    ]);
    DB::transaction(function () use ($departure, $cabin, $request): void {
        app(ClaimService::class)->claim($departure->stayDates(),
            collect([$cabin]),
            $request,
            ClaimKind::Hold,
            HoldType::Request,
            now()->addDay(),
        );
    });

    $this->actingAs($admin)
        ->postJson('/api/rms/bookings/'.$request->id.'/transition', [
            'to' => 'RELEASED',
            'reason' => 'Guest went elsewhere',
        ])
        ->assertOk();

    $rows = $this->actingAs($admin)
        ->getJson('/api/rms/bookings/audit')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    expect($rows[0]['what'])->toBe('Request released — hold returned to inventory');
    expect($rows[0]['why'])->toBe('Guest went elsewhere');
    expect($rows[1]['what'])->toBe('Reservation deleted');
    expect($rows[1]['why'])->toBe('Wrong date');

    $today = BusinessTime::now()->toDateString();
    $this->actingAs($admin)
        ->getJson('/api/rms/bookings/audit?from='.$today.'&to='.$today)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($admin)
        ->getJson('/api/rms/bookings/audit?from=2020-01-01&to=2020-01-02')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('audit from and to are galapagos calendar days', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $admin = adminUser();
    $id = $this->actingAs($admin)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure))
        ->assertCreated()
        ->json('bookings.0.id');

    $this->travelTo(CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC'));
    $this->actingAs($admin)
        ->deleteJson('/api/rms/bookings/'.$id, ['reason' => 'Night']);

    // 02:00 UTC is still 20 Sep in UTC but 19 Sep 20:00 in Galápagos (UTC−6).
    $this->actingAs($admin)
        ->getJson('/api/rms/bookings/audit?from=2026-09-19&to=2026-09-19')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($admin)
        ->getJson('/api/rms/bookings/audit?from=2026-09-20&to=2026-09-20')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('audit requires bookings.view_all', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/rms/bookings/audit')
        ->assertForbidden();
});
