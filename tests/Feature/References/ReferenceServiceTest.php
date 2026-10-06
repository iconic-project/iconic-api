<?php

declare(strict_types=1);

use App\Enums\PaymentKind;
use App\Enums\ReferenceType;
use App\Services\References\ReferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

function draw(ReferenceService $service, ReferenceType $type, ?CarbonImmutable $at = null): string
{
    return DB::transaction(fn (): string => $service->next($type, $at));
}

test('every reference type uses its format', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos');

    expect(draw($service, ReferenceType::Booking, $at))->toBe('ANK-2026-0001');
    expect(draw($service, ReferenceType::Request, $at))->toBe('ANK-R-2026-0001');
    expect(fn () => draw($service, ReferenceType::Departure))->toThrow(RuntimeException::class);
    expect(draw($service, ReferenceType::Block))->toBe('BLK-001');
    expect(draw($service, ReferenceType::Group))->toBe('GRP-001');
    expect(draw($service, ReferenceType::Offer))->toBe('OF-001');
    expect(draw($service, ReferenceType::Agency))->toBe('AG-001');
    expect(draw($service, ReferenceType::Invoice, $at))->toBe('INV-2026-0001');
});

test('numbers grow past the pad width and never wrap', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos');

    DB::transaction(fn () => $service->ensureAtLeast(ReferenceType::Booking, 9999, 2026));

    expect(draw($service, ReferenceType::Booking, $at))->toBe('ANK-2026-10000');
});

test('the first booking of a new year is 0001', function (): void {
    $service = app(ReferenceService::class);

    expect(draw($service, ReferenceType::Booking, CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos')))
        ->toBe('ANK-2026-0001');
    expect(draw($service, ReferenceType::Booking, CarbonImmutable::parse('2027-01-04', 'Pacific/Galapagos')))
        ->toBe('ANK-2027-0001');
});

test('the first invoice of a new year is 0001', function (): void {
    $service = app(ReferenceService::class);

    expect(draw($service, ReferenceType::Invoice, CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos')))
        ->toBe('INV-2026-0001');
    expect(draw($service, ReferenceType::Invoice, CarbonImmutable::parse('2027-01-04', 'Pacific/Galapagos')))
        ->toBe('INV-2027-0001');
});

test('an invoice at 2026-12-31 23:30 Galapagos is a 2026 invoice', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-12-31 23:30:00', 'Pacific/Galapagos');

    expect($at->utc()->year)->toBe(2027);
    expect(draw($service, ReferenceType::Invoice, $at))->toBe('INV-2026-0001');
});

test('bookings and requests have separate counters', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos');

    expect(draw($service, ReferenceType::Booking, $at))->toBe('ANK-2026-0001');
    expect(draw($service, ReferenceType::Request, $at))->toBe('ANK-R-2026-0001');
});

test('a booking at 2026-12-31 23:30 Galapagos is a 2026 booking', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-12-31 23:30:00', 'Pacific/Galapagos');

    expect($at->utc()->year)->toBe(2027);
    expect(draw($service, ReferenceType::Booking, $at))->toBe('ANK-2026-0001');
});

test('payment suffixes increment per booking and kind', function (): void {
    $service = app(ReferenceService::class);

    $firstDeposit = DB::transaction(fn (): string => $service->nextPayment('ANK-2026-0005', PaymentKind::Deposit));
    $secondDeposit = DB::transaction(fn (): string => $service->nextPayment('ANK-2026-0005', PaymentKind::Deposit));
    $balance = DB::transaction(fn (): string => $service->nextPayment('ANK-2026-0005', PaymentKind::Balance));
    $otherBooking = DB::transaction(fn (): string => $service->nextPayment('ANK-2026-0007', PaymentKind::Deposit));

    expect($firstDeposit)->toBe('ANK-2026-0005-D01');
    expect($secondDeposit)->toBe('ANK-2026-0005-D02');
    expect($balance)->toBe('ANK-2026-0005-B01');
    expect($otherBooking)->toBe('ANK-2026-0007-D01');
});

test('ensureAtLeast never lowers a counter', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos');

    DB::transaction(fn () => $service->ensureAtLeast(ReferenceType::Booking, 10, 2026));
    DB::transaction(fn () => $service->ensureAtLeast(ReferenceType::Booking, 5, 2026));

    expect(draw($service, ReferenceType::Booking, $at))->toBe('ANK-2026-0011');
});

test('a rolled-back draw reuses the number', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos');

    try {
        DB::transaction(function () use ($service, $at): void {
            expect($service->next(ReferenceType::Booking, $at))->toBe('ANK-2026-0001');

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('force rollback');
    }

    expect(draw($service, ReferenceType::Booking, $at))->toBe('ANK-2026-0001');
});

test('drawing outside a transaction throws', function (): void {
    app(ReferenceService::class)->next(ReferenceType::Booking);
})->throws(RuntimeException::class, 'References must be drawn inside a transaction.');
