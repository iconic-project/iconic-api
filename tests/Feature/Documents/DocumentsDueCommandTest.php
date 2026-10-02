<?php

declare(strict_types=1);

use App\Actions\Extras\AddBookingExtra;
use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DocumentKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Mail\Documents\ReminderMail;
use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\Payment;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
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
    CarbonImmutable::setTestNow();
    Storage::disk('documents')->deleteDirectory('/');
});

function reminderBooking(string $due = '2028-06-01'): Booking
{
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->yacht->cabins->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6301',
        'total' => 26600,
        'deposit_pct' => 10,
        'balance_due_date_override' => $due,
    ]);
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'status' => PaymentStatus::Settled,
        'amount' => 2660,
    ]);

    return $booking->fresh() ?? $booking;
}

function travelTo(string $date): void
{
    CarbonImmutable::setTestNow(BusinessTime::calendarDay($date)->setTime(12, 0));
}

test('reminders fire at minus 21 and minus 7 and not on the days around them', function (): void {
    $booking = reminderBooking();

    travelTo('2028-05-10');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(0);

    travelTo('2028-05-11');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(1);
    Mail::assertSent(ReminderMail::class, function (ReminderMail $mail): bool {
        if ($mail->days !== 21) {
            return false;
        }

        $token = BookingAccessToken::query()->where('booking_id', $mail->booking->id)->first();

        return $token instanceof BookingAccessToken
            && str_contains($mail->render(), $token->page_url)
            && ! str_contains($mail->render(), 'buy.stripe.com');
    });

    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(1);

    travelTo('2028-05-25');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(2);
    Mail::assertSent(ReminderMail::class, fn (ReminderMail $mail): bool => $mail->days === 7);
});

test('no reminder is sent after the cruise balance is paid', function (): void {
    $booking = reminderBooking();
    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Balance,
        'status' => PaymentStatus::Settled,
        'amount' => 23940,
    ]);

    travelTo('2028-05-11');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(0);
});

test('an OPS-007 extension moves the reminder keys', function (): void {
    $booking = reminderBooking('2028-06-01');

    travelTo('2028-05-11');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(1);

    $booking->balance_due_date_override = '2028-06-15';
    $booking->save();

    travelTo('2028-05-25');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->where('idempotency_key', 'like', '%2028-06-15%')->count())->toBe(1);
});

test('a reminder is not sent after the due date', function (): void {
    reminderBooking();
    travelTo('2028-06-02');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(0);
});

test('a booking confirmed 10 days before due gets the passed 21-day slot then the 7-day slot', function (): void {
    reminderBooking();

    travelTo('2028-05-22');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(1);
    Mail::assertSent(ReminderMail::class, fn (ReminderMail $mail): bool => $mail->days === 10);

    travelTo('2028-05-25');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(2);
});

test('a missed run spanning both reminder slots sends only the smallest N', function (): void {
    reminderBooking();
    travelTo('2028-05-27');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Delivery::query()->where('kind', DeliveryKind::Reminder)->count())->toBe(1);
    Mail::assertSent(ReminderMail::class, fn (ReminderMail $mail): bool => $mail->days === 5);
});

test('pretrip at T-45 and voucher at T-7 only with a transfer extra and a missed day is caught up', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2028-09-03');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->yacht->cabins->firstWhere('code', 'S2')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-6302',
        'total' => 26600,
    ]);

    travelTo('2028-07-19');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Pretrip)->count())->toBe(0);

    travelTo('2028-07-21');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Pretrip)->count())->toBe(1);

    travelTo('2028-08-28');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Voucher)->count())->toBe(0);

    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 2], adminUser());

    travelTo('2028-08-28');
    $this->artisan('iconic:documents-due')->assertSuccessful();
    expect(Document::query()->where('booking_id', $booking->id)->where('kind', DocumentKind::Voucher)->count())->toBe(1);
});

test('dry-run lists and writes nothing', function (): void {
    reminderBooking();
    travelTo('2028-05-11');

    $this->artisan('iconic:documents-due', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would send');

    expect(Delivery::query()->count())->toBe(0);
});
