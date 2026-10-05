<?php

declare(strict_types=1);

use App\Actions\Crm\RecordContactConsent;
use App\Actions\GuestExperience\RecordGuestResponse;
use App\Actions\Manifests\SendDataChaser;
use App\Enums\AlertKind;
use App\Enums\BookingStatus;
use App\Enums\ConsentCapturePoint;
use App\Enums\ConsentPurpose;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Enums\GuestResponseSource;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Mail\Alerts\AlertMail;
use App\Mail\Documents\DataChaserMail;
use App\Mail\Documents\DocumentMail;
use App\Mail\Documents\ReminderMail;
use App\Mail\Documents\ReviewRequestMail;
use App\Models\Alert;
use App\Models\AutomationSetting;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\CrmTask;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Guest;
use App\Models\Journey;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Support\Alerts\AlertKeys;
use App\Support\Automations\AutomationCatalogue;
use App\Support\Automations\AutomationGate;
use App\Support\BusinessTime;
use App\Support\GuestExperience\SurveyAnswers;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
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
    CarbonImmutable::setTestNow();
    Storage::disk('documents')->deleteDirectory('/');
});

test('built rows resolve and not-built rows name the gap', function (): void {
    $commands = array_keys(Artisan::all());
    $rows = AutomationCatalogue::all();
    $built = AutomationCatalogue::built();

    expect($rows)->toHaveCount(58)
        ->and($built)->toHaveCount(54)
        ->and(count($rows) - count($built))->toBe(4);

    foreach ($built as $row) {
        $location = $row->location;
        expect($location)->toBeString();
        $alert = is_string($location) && str_starts_with($location, 'alert:')
            ? AlertKind::tryFrom(substr($location, 6))
            : null;

        $journey = is_string($location) && str_starts_with($location, 'journey:')
            && Journey::query()->where('key', substr($location, 8))->exists();

        expect(
            class_exists((string) $location)
            || in_array($location, $commands, true)
            || $alert instanceof AlertKind
            || $journey,
        )->toBeTrue();
    }

    foreach ($rows as $row) {
        if ($row->built) {
            continue;
        }

        expect($row->location)->toBeNull()
            ->and($row->notBuiltNote)->not->toBeNull()
            ->and($row->switchable)->toBeFalse();
    }

    AutomationSetting::query()->create([
        'key' => AutomationCatalogue::DATA_CHASER,
        'enabled' => false,
        'disabled_reason' => 'ignored',
        'disabled_at' => now(),
    ]);
    AutomationSetting::query()->create([
        'key' => AutomationCatalogue::alertKey(AlertKind::OverdueBalance),
        'enabled' => false,
        'disabled_reason' => 'ignored',
        'disabled_at' => now(),
    ]);

    $gate = app(AutomationGate::class);

    expect($gate->allows(AutomationCatalogue::DATA_CHASER))->toBeTrue()
        ->and($gate->allows(AutomationCatalogue::alertKey(AlertKind::OverdueBalance)))->toBeTrue()
        ->and($gate->allows(AutomationCatalogue::BALANCE_REMINDER_21))->toBeTrue();
});

test('the catalogue is readable with panel.crm and a switch needs rules.manage', function (): void {
    $crm = User::factory()->create([
        'role_id' => Role::factory()->create([
            'permissions' => [Permission::PanelCrm],
        ])->id,
    ]);
    $outsider = User::factory()->create([
        'role_id' => Role::factory()->create([
            'permissions' => [Permission::PanelRms],
        ])->id,
    ]);
    $admin = adminUser();

    $this->actingAs($outsider)->getJson('/api/crm/automations')->assertForbidden();

    $index = $this->actingAs($crm)->getJson('/api/crm/automations')->assertOk();
    assertNoSensitiveFields($index);

    expect($index->json('data'))->toHaveCount(58)
        ->and($index->json('data.0.key'))->toBe('welcome_web_lead')
        ->and($index->json('data.0.built'))->toBeTrue()
        ->and($index->json('data.0.enabled'))->toBeTrue()
        ->and(collect($index->json('data'))->firstWhere('key', AutomationCatalogue::BALANCE_REMINDER_21)['switchable'])->toBeTrue()
        ->and(collect($index->json('data'))->firstWhere('key', AutomationCatalogue::DATA_CHASER)['switchable'])->toBeFalse();

    $this->actingAs($crm)->patchJson('/api/crm/automations/'.AutomationCatalogue::BALANCE_REMINDER_21, [
        'enabled' => false,
        'reason' => 'Paused for the season',
    ])->assertForbidden();

    $this->actingAs($admin)->patchJson('/api/crm/automations/'.AutomationCatalogue::BALANCE_REMINDER_21, [
        'enabled' => false,
    ])->assertStatus(422);

    $patched = $this->actingAs($admin)->patchJson('/api/crm/automations/'.AutomationCatalogue::BALANCE_REMINDER_21, [
        'enabled' => false,
        'reason' => 'Paused for the season',
    ])->assertOk();

    expect($patched->json('enabled'))->toBeFalse()
        ->and($patched->json('disabled_reason'))->toBe('Paused for the season')
        ->and($patched->json('disabled_by'))->toBe($admin->name);

    $this->actingAs($admin)->patchJson('/api/crm/automations/does-not-exist', [
        'enabled' => false,
        'reason' => 'Nope',
    ])->assertNotFound();

    $this->actingAs($admin)->patchJson('/api/crm/automations/'.AutomationCatalogue::alertKey(AlertKind::OverdueBalance), [
        'enabled' => false,
        'reason' => 'Turn the overdue notice off',
    ])->assertStatus(422)->assertJsonPath('message', AutomationCatalogue::REFUSAL);

    $this->actingAs($admin)->patchJson('/api/crm/automations/'.AutomationCatalogue::DATA_CHASER, [
        'enabled' => false,
        'reason' => 'Stop the chase',
    ])->assertStatus(422)->assertJsonPath('message', AutomationCatalogue::REFUSAL);

    $this->actingAs($admin)->patchJson('/api/crm/automations/wire_instructions', [
        'enabled' => false,
        'reason' => 'Not sent yet',
    ])->assertStatus(422)->assertJsonPath('message', AutomationCatalogue::REFUSAL);
});

test('a disabled balance reminder is not sent and the overdue flag alert and task still happen', function (): void {
    $admin = adminUser();
    $reason = 'Paused for the season';

    $this->actingAs($admin)->patchJson('/api/crm/automations/'.AutomationCatalogue::BALANCE_REMINDER_21, [
        'enabled' => false,
        'reason' => $reason,
    ])->assertOk();

    $overdue = overdueCabin([
        'reference' => 'ANK-2026-1414',
        'cabin_code' => 'S3',
        'departure' => ReservationFixtures::anamaraDeparture('2029-01-07'),
    ]);
    Artisan::call('iconic:flag-overdue');
    Artisan::call('iconic:crm-tasks');

    expect(ChangeHistory::query()->where('subject_id', $overdue->id)->where('event', 'booking.overdue_flagged')->exists())->toBeTrue()
        ->and(Alert::query()->where('kind', AlertKind::OverdueBalance)->where('booking_id', $overdue->id)->exists())->toBeTrue()
        ->and(CrmTask::query()->where('kind', TaskKind::OverdueDecision)->where('booking_id', $overdue->id)->exists())->toBeTrue();

    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-1415',
        'total' => 26600,
        'deposit_pct' => 10,
        'balance_due_date_override' => '2028-06-01',
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 2660,
    ]);

    CarbonImmutable::setTestNow(BusinessTime::calendarDay('2028-05-11')->setTime(12, 0));
    $this->artisan('iconic:documents-due')->assertSuccessful();

    $blocked = Delivery::query()->where('booking_id', $booking->id)->where('kind', DeliveryKind::Reminder)->first();

    expect($blocked)->not->toBeNull()
        ->and($blocked?->status)->toBe(DeliveryStatus::Blocked)
        ->and($blocked?->blocked_reason)->toBe($reason)
        ->and($blocked?->idempotency_key)->toContain(':21');

    Mail::assertNothingSent();

    expect(ChangeHistory::query()->where('event', 'automation.disabled')->where('reason', $reason)->exists())->toBeTrue()
        ->and(ChangeHistory::query()->where('subject_type', 'booking')->where('subject_id', $booking->id)->where('event', 'automation.skipped')->where('reason', $reason)->exists())->toBeTrue();

    Artisan::call('iconic:alerts');

    expect(Alert::query()->where('kind', AlertKind::DeliveryFailed)->count())->toBe(0)
        ->and(Alert::query()->where('kind', AlertKind::OverdueBalance)->where('booking_id', $overdue->id)->exists())->toBeTrue();

    CarbonImmutable::setTestNow(BusinessTime::calendarDay('2028-05-25')->setTime(12, 0));
    $this->artisan('iconic:documents-due')->assertSuccessful();

    Mail::assertSent(ReminderMail::class, fn (ReminderMail $mail): bool => $mail->days === 7);
    expect(Delivery::query()->where('booking_id', $booking->id)->where('kind', DeliveryKind::Reminder)->where('status', DeliveryStatus::Sent)->count())->toBe(1);
});

test('switching off the pre-trip email still issues the document', function (): void {
    $admin = adminUser();
    $this->actingAs($admin)->patchJson('/api/crm/automations/'.AutomationCatalogue::PRETRIP, [
        'enabled' => false,
        'reason' => 'Hold the itinerary email',
    ])->assertOk();

    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-1416',
        'total' => 26600,
    ]);

    CarbonImmutable::setTestNow(BusinessTime::calendarDay('2028-07-21')->setTime(12, 0));
    $this->artisan('iconic:documents-due')->assertSuccessful();

    $delivery = Delivery::query()->where('booking_id', $booking->id)->where('kind', DeliveryKind::Pretrip)->first();

    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Pretrip)->count())->toBe(1)
        ->and($delivery?->status)->toBe(DeliveryStatus::Blocked)
        ->and($delivery?->blocked_reason)->toBe('Hold the itinerary email');

    Mail::assertNotSent(DocumentMail::class);
});

test('a review request still obeys consent when the switch is on and stops when the switch is off', function (): void {
    $owner = managerUser();
    $without = automationReviewBooking('2028-10-16', 'ANK-AUTO-NO', $owner, 'auto-no-consent@example.com');

    app(RecordGuestResponse::class)->handle(
        $without['booking'],
        $without['lead'],
        SurveyAnswers::from(automationSurveyBody(9)),
        GuestResponseSource::Staff,
        $owner,
    );

    expect(Delivery::query()->where('kind', DeliveryKind::ReviewRequest)->count())->toBe(0);
    Mail::assertNothingSent();

    $with = automationReviewBooking('2028-10-23', 'ANK-AUTO-YES', $owner, 'auto-consent@example.com');
    automationGrantMarketing($with['contact'], $owner);

    $this->actingAs(adminUser())->patchJson('/api/crm/automations/'.AutomationCatalogue::REVIEW_REQUEST, [
        'enabled' => false,
        'reason' => 'No review asks this month',
    ])->assertOk();

    app(RecordGuestResponse::class)->handle(
        $with['booking'],
        $with['lead'],
        SurveyAnswers::from(automationSurveyBody(9)),
        GuestResponseSource::Staff,
        $owner,
    );

    $review = Delivery::query()->where('kind', DeliveryKind::ReviewRequest)->first();

    expect($review?->status)->toBe(DeliveryStatus::Blocked)
        ->and($review?->blocked_reason)->toBe('No review asks this month');
    Mail::assertNotSent(ReviewRequestMail::class);
});

test('a forced-off data chaser and a forced-off critical alert still send', function (): void {
    AutomationSetting::query()->create([
        'key' => AutomationCatalogue::DATA_CHASER,
        'enabled' => false,
        'disabled_reason' => 'should not stop the chase',
        'disabled_at' => now(),
    ]);
    AutomationSetting::query()->create([
        'key' => AutomationCatalogue::alertKey(AlertKind::OverdueBalance),
        'enabled' => false,
        'disabled_reason' => 'should not stop the alert email',
        'disabled_at' => now(),
    ]);

    $departure = ReservationFixtures::anamaraDeparture('2028-09-10');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S4')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-1417',
    ]);

    app(SendDataChaser::class)->handle($booking);

    expect(Delivery::query()->where('kind', DeliveryKind::DataChaser)->value('status'))->toBe(DeliveryStatus::Sent);
    Mail::assertSent(DataChaserMail::class);

    $recipient = adminUser(['email' => 'automation-alert@iconic.test']);
    $overdue = overdueCabin([
        'reference' => 'ANK-2026-1418',
        'cabin_code' => 'S1',
        'balance_due_date_override' => '2026-09-01',
        'departure' => ReservationFixtures::anamaraDeparture('2027-11-07'),
    ]);
    $alertKey = AlertKeys::overdue($overdue->id, '2026-09-01');
    $alert = Alert::factory()->critical()->create([
        'booking_id' => $overdue->id,
        'base_key' => $alertKey,
        'idempotency_key' => $alertKey,
        'title' => 'Critical balance',
        'sentence' => 'Still emailed.',
    ]);

    Artisan::call('iconic:alerts');

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo($recipient->email));
    expect($alert->fresh()?->emailed_at)->not->toBeNull();
});

/**
 * @return array{booking: Booking, contact: Contact, lead: Guest}
 */
function automationReviewBooking(string $date, string $reference, User $owner, string $email): array
{
    $departure = ReservationFixtures::anamaraDeparture($date);
    $contact = Contact::factory()->create(['email' => $email]);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'contact_id' => $contact->id,
        'owner_id' => $owner->id,
        'status' => BookingStatus::CheckedOut,
        'reference' => $reference,
    ]);
    $lead = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => $email,
    ]);

    return compact('booking', 'contact', 'lead');
}

/**
 * @return array{score: int, rec: int, why: string, best: string, better: string, crew: string}
 */
function automationSurveyBody(int $score): array
{
    return [
        'score' => $score,
        'rec' => 9,
        'why' => 'the wildlife',
        'best' => 'the landing',
        'better' => 'more time ashore',
        'crew' => 'the guide',
    ];
}

function automationGrantMarketing(Contact $contact, User $actor): void
{
    app(RecordContactConsent::class)->handle(
        $contact,
        ConsentPurpose::Marketing,
        granted: true,
        version: 'v1',
        capturePoint: ConsentCapturePoint::Staff,
        recordedBy: $actor,
        howObtained: 'the guest asked for news by email',
    );
}
