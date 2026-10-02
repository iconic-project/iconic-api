<?php

declare(strict_types=1);

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\ChangeHistory;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Stripe\FakeStripeGateway;
use App\Support\Payments\ReconciliationReport;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function stripeGateway(): FakeStripeGateway
{
    $gateway = app(FakeStripeGateway::class);
    assert($gateway instanceof FakeStripeGateway);

    return $gateway;
}

test('reconciliation buckets a matched, unmatched and amount-mismatch charge', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-0540']);
    $created = CarbonImmutable::parse('2026-09-19 15:00:00', 'UTC');

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::StripeLink,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
        'gateway_id' => 'pi_matched',
        'reference' => 'ANK-2026-0540-D01',
    ]);

    $other = pendingCabin([
        'departure' => ReservationFixtures::anamaraDeparture('2028-02-06'),
        'reference' => 'ANK-2026-0541',
        'room_id' => ReservationFixtures::anamaraDeparture('2028-02-06')->property->cabins->firstWhere('code', 'S2')?->id,
    ]);

    Payment::factory()->create([
        'booking_id' => $other->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::CardStripe,
        'amount' => 2000,
        'status' => PaymentStatus::Settled,
        'gateway_id' => 'pi_mismatch',
        'reference' => 'ANK-2026-0541-D01',
    ]);

    $gateway = stripeGateway();
    $gateway->seedCharge(FakeStripeGateway::charge('ch_matched', 'pi_matched', 2660, $created, 'Deposit'));
    $gateway->seedCharge(FakeStripeGateway::charge('ch_unmatched', 'pi_unmatched', 3791, $created, 'Unknown'));
    $gateway->seedCharge(FakeStripeGateway::charge('ch_review', 'pi_mismatch', 2660, $created, 'Wrong amount'));

    $this->actingAs(adminUser())
        ->getJson('/api/rms/payments/reconciliation?from=2026-09-19&to=2026-09-19')
        ->assertOk()
        ->assertJsonPath('counts.matched', 1)
        ->assertJsonPath('counts.in_gateway_not_rms', 1)
        ->assertJsonPath('counts.to_review', 1)
        ->assertJsonPath('counts.gateway', 3)
        ->assertJsonPath('counts.discrepancies', 2)
        ->assertJsonPath('matched.0.payment_intent', 'pi_matched')
        ->assertJsonPath('in_gateway_not_rms.0.stripe_id', 'ch_unmatched')
        ->assertJsonPath('to_review.0.payment_intent', 'pi_mismatch')
        ->assertJsonPath('meta.mode', 'test')
        ->assertJsonPath('note', ReconciliationReport::WIRES_NOTE);
});

test('reconciliation matches a charge payment intent to the settlement row not a refund row', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-0542']);
    $created = CarbonImmutable::parse('2026-09-19 16:00:00', 'UTC');

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Deposit,
        'method' => PaymentMethod::StripeLink,
        'amount' => 2660,
        'status' => PaymentStatus::Settled,
        'gateway_id' => 'pi_with_refund',
        'reference' => 'ANK-2026-0542-D01',
    ]);

    Payment::factory()->create([
        'booking_id' => $booking->id,
        'kind' => PaymentKind::Refund,
        'method' => PaymentMethod::StripeLink,
        'amount' => -1000,
        'status' => PaymentStatus::Refunded,
        'gateway_id' => 're_with_settlement',
        'reference' => 'ANK-2026-0542-R01',
    ]);

    stripeGateway()->seedCharge(FakeStripeGateway::charge(
        'ch_with_refund',
        'pi_with_refund',
        2660,
        $created,
        'Deposit later refunded',
    ));

    $this->actingAs(adminUser())
        ->getJson('/api/rms/payments/reconciliation?from=2026-09-19&to=2026-09-19')
        ->assertOk()
        ->assertJsonPath('counts.matched', 1)
        ->assertJsonPath('counts.to_review', 0)
        ->assertJsonPath('counts.in_gateway_not_rms', 0)
        ->assertJsonPath('matched.0.payment_intent', 'pi_with_refund')
        ->assertJsonPath('matched.0.ledger_amount', 2660);
});

test('from and to are galapagos calendar days converted to utc before stripe created bounds', function (): void {
    $boundary = CarbonImmutable::parse('2026-09-20 02:00:00', 'UTC');

    stripeGateway()->seedCharge(FakeStripeGateway::charge(
        'ch_boundary',
        'pi_boundary',
        100,
        $boundary,
        'Night',
    ));

    $this->actingAs(adminUser())
        ->getJson('/api/rms/payments/reconciliation?from=2026-09-19&to=2026-09-19')
        ->assertOk()
        ->assertJsonPath('counts.in_gateway_not_rms', 1);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/payments/reconciliation?from=2026-09-20&to=2026-09-20')
        ->assertOk()
        ->assertJsonPath('counts.in_gateway_not_rms', 0)
        ->assertJsonPath('counts.matched', 0)
        ->assertJsonPath('counts.to_review', 0);
});

test('applying an unmatched charge produces the same settled payment as the webhook', function (): void {
    $booking = pendingCabin(['reference' => 'ANK-2026-0543']);
    $created = CarbonImmutable::parse('2026-09-19 12:00:00', 'UTC');

    stripeGateway()->seedCharge(FakeStripeGateway::charge(
        'ch_apply',
        'pi_apply',
        2660,
        $created,
        'Deposit via payment link',
    ));

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/payments/reconciliation/apply', [
            'stripe_id' => 'ch_apply',
            'booking_id' => $booking->id,
            'kind' => PaymentKind::Deposit->value,
        ])
        ->assertForbidden();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/payments/reconciliation/apply', [
            'stripe_id' => 'ch_apply',
            'booking_id' => $booking->id,
            'kind' => PaymentKind::Deposit->value,
        ])
        ->assertCreated()
        ->assertJsonPath('method', PaymentMethod::CardStripe->value)
        ->assertJsonPath('gateway_id', 'pi_apply')
        ->assertJsonPath('status', PaymentStatus::Settled->value)
        ->assertJsonPath('amount', 2660);

    expect($booking->fresh()->status->value)->toBe('CONFIRMED');

    $history = ChangeHistory::query()
        ->where('subject_id', $booking->id)
        ->where('event', 'payment.recorded')
        ->firstOrFail();

    expect($history->after['method'])->toBe(PaymentMethod::CardStripe->value);
});

test('reconciliation requires bookings.view_all', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->getJson('/api/rms/payments/reconciliation?from=2026-09-19&to=2026-09-19')
        ->assertForbidden();
});
