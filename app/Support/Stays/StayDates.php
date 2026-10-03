<?php

declare(strict_types=1);

namespace App\Support\Stays;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A stay is the half-open date interval [check_in, check_out). Pure dates: no time zone.
 */
final readonly class StayDates
{
    private function __construct(
        private CarbonImmutable $checkIn,
        private CarbonImmutable $checkOut,
    ) {}

    public static function of(string|CarbonInterface $checkIn, string|CarbonInterface $checkOut): self
    {
        $in = self::date($checkIn);
        $out = self::date($checkOut);

        if ($out->lessThanOrEqualTo($in)) {
            throw new InvalidArgumentException('Check-out must be after check-in.');
        }

        return new self($in, $out);
    }

    public static function forNights(string|CarbonInterface $checkIn, int $nights): self
    {
        $in = self::date($checkIn);

        return self::of($in, $in->addDays($nights));
    }

    public function checkIn(): CarbonImmutable
    {
        return $this->checkIn;
    }

    public function checkOut(): CarbonImmutable
    {
        return $this->checkOut;
    }

    public function nights(): int
    {
        return self::daysBetween($this->checkIn, $this->checkOut);
    }

    /**
     * Nights occupied: check_in through the day before check_out.
     *
     * @return iterable<int, CarbonImmutable>
     */
    public function eachNight(): iterable
    {
        $night = $this->checkIn;

        while ($night->lessThan($this->checkOut)) {
            yield $night;
            $night = $night->addDay();
        }
    }

    public function lastNight(): CarbonImmutable
    {
        return $this->checkOut->subDay();
    }

    public function contains(string|CarbonInterface $night): bool
    {
        $date = self::date($night);

        return $date->greaterThanOrEqualTo($this->checkIn) && $date->lessThan($this->checkOut);
    }

    public function overlaps(self $other): bool
    {
        return $this->checkIn->lessThan($other->checkOut)
            && $other->checkIn->lessThan($this->checkOut);
    }

    public function equals(self $other): bool
    {
        return $this->checkIn->equalTo($other->checkIn)
            && $this->checkOut->equalTo($other->checkOut);
    }

    /**
     * @return array{check_in: string, check_out: string, nights: int}
     */
    public function toArray(): array
    {
        return [
            'check_in' => $this->checkIn->toDateString(),
            'check_out' => $this->checkOut->toDateString(),
            'nights' => $this->nights(),
        ];
    }

    private static function date(string|CarbonInterface $value): CarbonImmutable
    {
        $string = $value instanceof CarbonInterface ? $value->format('Y-m-d') : $value;

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $string, 'UTC');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("Invalid calendar date [{$string}].");
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $string) {
            throw new InvalidArgumentException("Invalid calendar date [{$string}].");
        }

        return $parsed;
    }

    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }
}
