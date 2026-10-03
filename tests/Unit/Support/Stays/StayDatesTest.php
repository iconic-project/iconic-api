<?php

declare(strict_types=1);

use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

test('a stay counts nights across a month, a year and a leap day', function (): void {
    $one = StayDates::of('2026-03-01', '2026-03-02');
    $week = StayDates::forNights('2026-03-01', 7);
    $month = StayDates::of('2026-01-30', '2026-02-02');
    $year = StayDates::of(CarbonImmutable::parse('2026-12-30 15:00:00', 'UTC'), '2027-01-02');
    $leap = StayDates::of('2028-02-28', '2028-03-01');

    expect($one->nights())->toBe(1)
        ->and($one->toArray())->toBe([
            'check_in' => '2026-03-01',
            'check_out' => '2026-03-02',
            'nights' => 1,
        ])
        ->and($one->lastNight()->toDateString())->toBe('2026-03-01')
        ->and($week->nights())->toBe(7)
        ->and($week->checkOut()->toDateString())->toBe('2026-03-08')
        ->and($month->nights())->toBe(3)
        ->and($year->nights())->toBe(3)
        ->and($leap->nights())->toBe(2);
});

test('each night stops before check-out', function (): void {
    $stay = StayDates::of('2028-02-28', '2028-03-01');
    $nights = [];

    foreach ($stay->eachNight() as $night) {
        $nights[] = $night->toDateString();
    }

    expect($nights)->toBe(['2028-02-28', '2028-02-29'])
        ->and($nights)->not->toContain('2028-03-01');
});

test('contains is the occupied nights only', function (): void {
    $stay = StayDates::of('2026-03-01', '2026-03-04');

    expect($stay->contains('2026-02-28'))->toBeFalse()
        ->and($stay->contains('2026-03-01'))->toBeTrue()
        ->and($stay->contains('2026-03-03'))->toBeTrue()
        ->and($stay->contains('2026-03-04'))->toBeFalse();
});

test('touching stays do not overlap', function (): void {
    $first = StayDates::of('2026-03-01', '2026-03-04');
    $touching = StayDates::of('2026-03-04', '2026-03-06');
    $overlap = StayDates::of('2026-03-03', '2026-03-08');
    $inside = StayDates::of('2026-03-02', '2026-03-03');
    $before = StayDates::of('2026-02-01', '2026-02-20');
    $same = StayDates::forNights('2026-03-01', 3);

    expect($first->overlaps($touching))->toBeFalse()
        ->and($touching->overlaps($first))->toBeFalse()
        ->and($first->overlaps($overlap))->toBeTrue()
        ->and($first->overlaps($inside))->toBeTrue()
        ->and($first->overlaps($before))->toBeFalse()
        ->and($first->equals($same))->toBeTrue()
        ->and($first->equals($touching))->toBeFalse();
});

test('check-out on or before check-in is rejected', function (): void {
    expect(fn () => StayDates::of('2026-03-01', '2026-03-01'))
        ->toThrow(InvalidArgumentException::class, 'Check-out must be after check-in.')
        ->and(fn () => StayDates::of('2026-03-02', '2026-03-01'))
        ->toThrow(InvalidArgumentException::class, 'Check-out must be after check-in.')
        ->and(fn () => StayDates::forNights('2026-03-01', 0))
        ->toThrow(InvalidArgumentException::class, 'Check-out must be after check-in.');
});

test('a calendar date must be a real Y-m-d', function (): void {
    expect(fn () => StayDates::of('2028-02-31', '2028-03-02'))
        ->toThrow(InvalidArgumentException::class, 'Invalid calendar date [2028-02-31].')
        ->and(fn () => StayDates::of('not-a-date', '2026-03-02'))
        ->toThrow(InvalidArgumentException::class, 'Invalid calendar date [not-a-date].');
});
