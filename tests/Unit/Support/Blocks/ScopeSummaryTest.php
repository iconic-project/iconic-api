<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Support\Blocks\ConflictMessage;
use App\Support\Blocks\ScopeSummary;
use Carbon\CarbonImmutable;

test('a stay range lists the rooms, the checkout day, and the night count', function (): void {
    expect(ScopeSummary::format(
        ['101', '102'],
        CarbonImmutable::parse('2028-03-03'),
        CarbonImmutable::parse('2028-03-06'),
    ))->toBe('Rooms 101, 102 · Fri 3 – Mon 6 Mar 2028 · 3 nights');
});

test('one room and one night stay singular', function (): void {
    expect(ScopeSummary::format(
        ['204'],
        CarbonImmutable::parse('2028-03-03'),
        CarbonImmutable::parse('2028-03-04'),
    ))->toBe('Room 204 · Fri 3 – Sat 4 Mar 2028 · 1 night');
});

test('a range that crosses a month names the start month', function (): void {
    expect(ScopeSummary::format(
        ['101'],
        CarbonImmutable::parse('2028-03-30'),
        CarbonImmutable::parse('2028-04-02'),
    ))->toBe('Room 101 · Thu 30 Mar – Sun 2 Apr 2028 · 3 nights');
});

test('the conflict sentence names the first night and the holder', function (): void {
    expect(ConflictMessage::line(
        'Room 204',
        CarbonImmutable::parse('2028-03-04'),
        ClaimKind::Booking,
        'ANK-2028-0012',
    ))->toBe('Room 204 is sold on Sat 4 Mar 2028 (ANK-2028-0012)');
});
