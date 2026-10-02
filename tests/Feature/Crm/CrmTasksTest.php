<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Crm\CreateUnboundDeal;
use App\Enums\ActivityKind;
use App\Enums\BookingStatus;
use App\Enums\DealStage;
use App\Enums\DealType;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Events\PaymentAwaitingWire;
use App\Events\RefundRequested;
use App\Listeners\RaiseTasksOnBookingStatusChanged;
use App\Models\Booking;
use App\Models\CharterEnquiry;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\CrmTask;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Crm\TaskDue;
use App\Support\Crm\TaskSweep;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a response sla skips the weekend and a holiday pushes the next business day', function (): void {
    $rules = BusinessRulesDocument::fromArray(BusinessRulesDocument::initial());
    $friday = CarbonImmutable::parse('2026-09-18 17:00:00', BusinessTime::zone());

    expect(TaskDue::responseHours($friday, $rules)->setTimezone(BusinessTime::zone())->format('D H:i'))
        ->not->toBe('Sat')
        ->and(TaskDue::responseHours($friday, $rules)->setTimezone(BusinessTime::zone())->isWeekend())->toBeFalse();

    $document = BusinessRulesDocument::initial();
    $document['holds']['holidays'] = ['2026-09-21'];
    $holidayRules = BusinessRulesDocument::fromArray($document);
    $withHoliday = TaskDue::businessDays($friday, 1, $holidayRules);
    $without = TaskDue::businessDays($friday, 1, $rules);

    expect($withHoliday->greaterThan($without))->toBeTrue();
    expect($withHoliday->setTimezone(BusinessTime::zone())->format('Y-m-d'))->toBe('2026-09-22');
});

test('each system kind is raised once and the sweep does not raise it again', function (): void {
    $actor = managerUser();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 17:00:00', BusinessTime::zone()));
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    $request = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'client' => ['email' => 'task-request@iconic.test'],
        ]),
        $actor,
    );

    expect(CrmTask::query()->where('kind', TaskKind::RequestResponse)->count())->toBe(1);
    BookingCreated::dispatch($request);
    Artisan::call('iconic:crm-tasks');
    expect(CrmTask::query()->where('idempotency_key', 'request:'.$request->id)->count())->toBe(1);

    $held = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::OnHoldAgency,
        'reference' => 'ANK-CAP-1',
    ]);
    $overdue = Booking::factory()->create([
        'departure_id' => ReservationFixtures::anamaraDeparture('2027-12-05')->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-OVER-1',
        'balance_due_date_override' => '2026-09-01',
        'total' => 26600,
    ]);
    $wire = Payment::factory()->create([
        'booking_id' => $held->id,
        'status' => PaymentStatus::AwaitingWire,
        'amount' => 5000,
    ]);
    $refund = RefundRequest::factory()->create([
        'booking_id' => $held->id,
        'status' => 'PENDING',
        'refund_due' => 1000,
    ]);
    $enquiry = CharterEnquiry::factory()->create();
    $owner = salesExecUser();
    $deal = app(CreateUnboundDeal::class)->handle(
        Contact::factory()->create(),
        $owner,
        'Quoted lead',
        DealType::Fit,
        DealStage::Quoted,
        8000,
        null,
    );

    Artisan::call('iconic:crm-tasks');

    expect(CrmTask::query()->where('kind', TaskKind::CommissionCap)->where('booking_id', $held->id)->count())->toBe(1);
    expect(CrmTask::query()->where('kind', TaskKind::OverdueDecision)->where('booking_id', $overdue->id)->count())->toBe(1);
    expect(CrmTask::query()->where('kind', TaskKind::WireWindow)->where('payment_id', $wire->id)->count())->toBe(1);
    expect(CrmTask::query()->where('kind', TaskKind::RefundDecision)->where('refund_request_id', $refund->id)->count())->toBe(1);
    expect(CrmTask::query()->where('kind', TaskKind::CharterQuote)->where('charter_enquiry_id', $enquiry->id)->count())->toBe(1);
    expect(CrmTask::query()->where('kind', TaskKind::DealQuote)->where('deal_id', $deal->id)->count())->toBe(1);

    $before = CrmTask::query()->count();
    PaymentAwaitingWire::dispatch($wire);
    RefundRequested::dispatch($refund);
    Artisan::call('iconic:crm-tasks');

    expect(CrmTask::query()->where('kind', TaskKind::WireWindow)->count())->toBe(1);
    expect(CrmTask::query()->where('kind', TaskKind::RefundDecision)->count())->toBe(1);
    expect(CrmTask::query()->count())->toBe($before);
});

test('a cleared booking auto-closes the task and does not reopen it', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
            'client' => ['email' => 'task-close@iconic.test'],
        ]),
        $actor,
    );
    $before = Booking::query()->count();

    $booking->forceFill(['status' => BookingStatus::Confirmed])->save();
    Artisan::call('iconic:crm-tasks');

    $task = CrmTask::query()->where('idempotency_key', 'request:'.$booking->id)->firstOrFail();
    expect($task->status)->toBe(TaskStatus::AutoClosed);
    expect($task->outcome)->toBe('Resolved in the RMS');

    Artisan::call('iconic:crm-tasks');
    expect($task->fresh()?->status)->toBe(TaskStatus::AutoClosed);
    expect(CrmTask::query()->where('idempotency_key', 'request:'.$booking->id)->count())->toBe(1);
    expect(Booking::query()->count())->toBe($before);
    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
});

test('releasing a request closes its response task without the sweep', function (): void {
    $actor = managerUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-28');
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'cabins' => [['cabin_code' => 'S2', 'adults' => 2, 'children' => 0]],
            'client' => ['email' => 'task-release@iconic.test'],
        ]),
        $actor,
    );

    app(TaskSweep::class)->onBookingStatusChanged($booking, BookingStatus::Waitlisted, BookingStatus::Requested);

    $task = CrmTask::query()->where('idempotency_key', 'request:'.$booking->id)->firstOrFail();
    expect($task->status)->toBe(TaskStatus::Open);

    $booking->forceFill(['status' => BookingStatus::Released])->save();
    app(RaiseTasksOnBookingStatusChanged::class)->handle(
        new BookingStatusChanged($booking, BookingStatus::Requested, BookingStatus::Released),
    );

    expect($task->fresh()?->status)->toBe(TaskStatus::AutoClosed);
    expect($task->fresh()?->outcome)->toBe('Resolved in the RMS');
});

test('visibility follows ownership and needs_permission', function (): void {
    $owner = salesExecUser(['name' => 'Task Owner']);
    $other = salesExecUser(['name' => 'Other Exec']);
    $contact = Contact::factory()->create();

    $this->actingAs($owner)->postJson('/api/crm/tasks', [
        'title' => 'Call back',
        'due_at' => now()->addDay()->toIso8601String(),
        'contact_id' => $contact->id,
    ])->assertOk();

    $mine = $this->actingAs($owner)->getJson('/api/crm/tasks?scope=mine')->assertOk();
    expect(collect($mine->json('data'))->pluck('title'))->toContain('Call back');

    $hidden = $this->actingAs($other)->getJson('/api/crm/tasks?scope=mine')->assertOk();
    expect(collect($hidden->json('data'))->pluck('title'))->not->toContain('Call back');

    $this->actingAs($other)->getJson('/api/crm/tasks?scope=all')->assertForbidden();

    $admin = $this->actingAs(adminUser())->getJson('/api/crm/tasks?scope=all')->assertOk();
    expect(collect($admin->json('data'))->pluck('title'))->toContain('Call back');

    $enquiry = CharterEnquiry::factory()->create();
    Artisan::call('iconic:crm-tasks');
    $unassigned = $this->actingAs($other)->getJson('/api/crm/tasks?scope=unassigned')->assertOk();
    expect(collect($unassigned->json('data'))->pluck('kind'))->toContain(TaskKind::CharterQuote->value);

    $departure = ReservationFixtures::anamaraDeparture('2027-12-12');
    $overdue = Booking::factory()->create([
        'departure_id' => $departure->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-OVER-2',
        'balance_due_date_override' => '2026-09-01',
        'total' => 26600,
    ]);
    Artisan::call('iconic:crm-tasks');

    $decider = userWithPermissions([Permission::PanelCrm, Permission::BookingsOverdueDecision]);
    $seen = $this->actingAs($decider)->getJson('/api/crm/tasks?scope=mine')->assertOk();
    assertNoSensitiveFields($seen);
    expect(collect($seen->json('data'))->pluck('booking.id'))->toContain($overdue->id);

    $system = CrmTask::query()->where('kind', TaskKind::CharterQuote)->where('charter_enquiry_id', $enquiry->id)->firstOrFail();
    $this->actingAs(adminUser())
        ->postJson('/api/crm/tasks/'.$system->id.'/cancel', ['outcome' => 'Not mine'])
        ->assertStatus(422);
});

/**
 * @param  list<Permission>  $permissions
 */
function userWithPermissions(array $permissions): User
{
    $role = Role::factory()->create(['permissions' => $permissions]);

    return User::factory()->create(['role_id' => $role->id]);
}

test('completing writes one activity and leaves the ledger untouched', function (): void {
    $owner = salesExecUser();
    $contact = Contact::factory()->create();
    $created = $this->actingAs($owner)->postJson('/api/crm/tasks', [
        'title' => 'Note the call',
        'due_at' => now()->subHour()->toIso8601String(),
        'contact_id' => $contact->id,
    ])->assertOk();

    $bookings = Booking::query()->count();
    $payments = Payment::query()->count();
    $refunds = RefundRequest::query()->count();
    $enquiries = CharterEnquiry::query()->count();

    $this->actingAs($owner)
        ->postJson('/api/crm/tasks/'.$created->json('id').'/complete', ['outcome' => 'Spoke to them'])
        ->assertOk()
        ->assertJsonPath('status', 'DONE');

    expect(ContactActivity::query()->where('kind', ActivityKind::TaskCompleted)->count())->toBe(1);
    expect(ContactActivity::query()->value('body'))->toBe('Spoke to them');
    expect(Booking::query()->count())->toBe($bookings);
    expect(Payment::query()->count())->toBe($payments);
    expect(RefundRequest::query()->count())->toBe($refunds);
    expect(CharterEnquiry::query()->count())->toBe($enquiries);

    $list = $this->actingAs($owner)->getJson('/api/crm/tasks?scope=mine&due=overdue')->assertOk();
    expect($list->json('meta.kpis.quote_sla_hours'))->toBe(24);
});

test('an activity rejects a passport-like body and a date of birth', function (): void {
    $owner = salesExecUser();
    $contact = Contact::factory()->create();

    $passport = $this->actingAs($owner)->postJson('/api/crm/contacts/'.$contact->id.'/activities', [
        'kind' => 'NOTE',
        'body' => 'Passport AB12345678 on file',
    ])->assertStatus(422);

    expect(json_encode($passport->json()))->not->toContain('AB12345678');

    $this->actingAs($owner)->postJson('/api/crm/contacts/'.$contact->id.'/activities', [
        'kind' => 'NOTE',
        'body' => 'Born 1984-03-02',
    ])->assertStatus(422);

    $saved = $this->actingAs($owner)->postJson('/api/crm/contacts/'.$contact->id.'/activities', [
        'kind' => 'CALL',
        'body' => 'Asked about the November departure',
    ])->assertOk();
    assertNoSensitiveFields($saved);
});
