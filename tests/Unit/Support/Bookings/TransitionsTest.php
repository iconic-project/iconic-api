<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Support\Bookings\Transitions;

test('the sprint 4 table matches TRANS minus sprint 5 states', function (): void {
    expect(array_map(fn (BookingStatus $status): string => $status->value, Transitions::targets(BookingStatus::Requested)))
        ->toBe(['PENDING_PAYMENT', 'CONFIRMED', 'RELEASED', 'CANCELLED']);
    expect(array_map(fn (BookingStatus $status): string => $status->value, Transitions::targets(BookingStatus::PendingPayment)))
        ->toBe(['CONFIRMED', 'CANCELLED']);
    expect(array_map(fn (BookingStatus $status): string => $status->value, Transitions::targets(BookingStatus::Confirmed)))
        ->toBe(['FULLY_PAID', 'CANCELLED']);
    expect(array_map(fn (BookingStatus $status): string => $status->value, Transitions::targets(BookingStatus::FullyPaid)))
        ->toBe(['CONFIRMED', 'CANCELLED_POSTPAID']);
    expect(Transitions::targets(BookingStatus::InHouse))->toBe([]);
    expect(Transitions::frontDeskPointer(BookingStatus::InHouse))->toBe('Check in at POST /api/rms/bookings/{booking}/check-in.');
    expect(Transitions::frontDeskPointer(BookingStatus::CheckedOut))->toBe('Check out at POST /api/rms/bookings/{booking}/check-out.');
    expect(Transitions::frontDeskPointer(BookingStatus::NoShow))->toBe('Mark a no-show at POST /api/rms/bookings/{booking}/no-show.');
    expect(array_map(fn (BookingStatus $status): string => $status->value, Transitions::targets(BookingStatus::OnHoldAgency)))
        ->toBe(['CONFIRMED', 'RELEASED', 'CANCELLED']);

    foreach ([
        BookingStatus::CheckedOut,
        BookingStatus::Cancelled,
        BookingStatus::CancelledPostpaid,
        BookingStatus::Released,
        BookingStatus::Overdue,
        BookingStatus::Waitlisted,
    ] as $terminal) {
        expect(Transitions::targets($terminal))->toBe([]);
    }
});

test('reason is required for cancellations, manual fully paid and release', function (): void {
    expect(Transitions::reasonRequired(BookingStatus::Cancelled))->toBeTrue();
    expect(Transitions::reasonRequired(BookingStatus::CancelledPostpaid))->toBeTrue();
    expect(Transitions::reasonRequired(BookingStatus::FullyPaid))->toBeTrue();
    expect(Transitions::reasonRequired(BookingStatus::Released))->toBeTrue();
    expect(Transitions::reasonRequired(BookingStatus::Confirmed))->toBeFalse();
    expect(Transitions::reasonRequired(BookingStatus::InHouse))->toBeFalse();
});

test('the generic date guard does not admit front-desk statuses', function (): void {
    expect(Transitions::targets(BookingStatus::FullyPaid))->not->toContain(BookingStatus::InHouse);
    expect(Transitions::targets(BookingStatus::InHouse))->not->toContain(BookingStatus::CheckedOut);
    expect(Transitions::dateGuardAllows(BookingStatus::Cancelled, '2027-01-01', '2027-11-07', '2027-11-14'))->toBeTrue();
});

test('the enum delegates to the table', function (): void {
    expect(BookingStatus::Confirmed->allowedTransitions())->toBe(Transitions::targets(BookingStatus::Confirmed));
});
