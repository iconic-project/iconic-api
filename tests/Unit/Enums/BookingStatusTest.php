<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Support\Bookings\LegacyStatus;

test('in house holds inventory and confirmed-or-later includes the stay statuses', function (): void {
    expect(BookingStatus::InHouse->holdsInventory())->toBeTrue()
        ->and(BookingStatus::InHouse->isConfirmedOrLater())->toBeTrue()
        ->and(BookingStatus::CheckedOut->isConfirmedOrLater())->toBeTrue()
        ->and(BookingStatus::NoShow->isConfirmedOrLater())->toBeTrue()
        ->and(BookingStatus::NoShow->holdsInventory())->toBeTrue()
        ->and(BookingStatus::Released->holdsInventory())->toBeFalse();
});

test('history display maps retired booking statuses and leaves the stored words alone', function (): void {
    expect(LegacyStatus::value('ON_BOARD'))->toBe('IN_HOUSE')
        ->and(LegacyStatus::value('COMPLETED'))->toBe('CHECKED_OUT')
        ->and(LegacyStatus::value('CONFIRMED'))->toBe('CONFIRMED')
        ->and(LegacyStatus::text('Status ON BOARD → COMPLETED'))->toBe('Status IN HOUSE → CHECKED OUT')
        ->and(LegacyStatus::payload([
            'status' => 'ON_BOARD',
            'what' => 'Status FULLY PAID → ON BOARD',
        ]))->toBe([
            'status' => 'IN_HOUSE',
            'what' => 'Status FULLY PAID → IN HOUSE',
        ]);
});
