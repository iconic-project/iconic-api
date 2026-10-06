<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\BookingStatus;
use App\Enums\ChannelOfOrigin;
use App\Enums\ChannelOfOriginGroup;
use App\Models\Booking;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Inventory\NightAvailability;
use App\Support\Bookings\SoldOn;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hotel KPIs for a night range. Inventory comes from NightAvailability.
 * Room revenue comes from night lines, one night at a time.
 */
final class HotelKpis
{
    /** @var list<BookingStatus> */
    private const array SOLD = [
        BookingStatus::Confirmed,
        BookingStatus::FullyPaid,
        BookingStatus::InHouse,
        BookingStatus::CheckedOut,
        BookingStatus::Overdue,
    ];

    /** @var list<BookingStatus> */
    private const array CANCELLED = [
        BookingStatus::Cancelled,
        BookingStatus::CancelledPostpaid,
    ];

    public function __construct(private readonly NightAvailability $availability) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     room_nights_sold: int,
     *     room_nights_available: int,
     *     occupancy: string|null,
     *     room_revenue: int,
     *     adr: int|null,
     *     revpar: int|null,
     *     pickup_room_nights: int|null,
     *     pickup_days: int,
     *     average_length_of_stay: string|null,
     *     length_of_stay_bookings: int,
     *     lead_time_days: int|null,
     *     lead_time_bookings: int,
     *     cancellation_rate: string|null,
     *     no_show_rate: string|null,
     *     legacy_room_revenue: bool,
     *     has_activity: bool,
     *     channel_mix: list<array{channel: string, bookings: int, room_nights: int, room_revenue: int}>,
     *     nights: list<array{night: string, room_nights_sold: int, room_nights_available: int, occupancy: string|null, room_revenue: int, adr: int|null, revpar: int|null, pickup_room_nights: int}>
     * }
     */
    public function measure(
        string $from,
        string $until,
        string $today,
        int $pickupDays,
        ?int $propertyId = null,
        ?int $roomTypeId = null,
        ?ChannelOfOriginGroup $channel = null,
    ): array {
        $nights = self::nightsBetween($from, $until);
        $inventory = $this->inventory($nights, $propertyId, $roomTypeId);
        $rows = $this->rows($from, $until, $propertyId, $roomTypeId, $channel);

        return self::compute($from, $until, $today, $pickupDays, $nights, $inventory, $rows, $channel !== null);
    }

    /**
     * @param  list<string>  $nights
     * @param  array<string, array{sold: int, available: int}>  $inventory
     * @param  list<array{status: string, check_in: string, check_out: string, nights: int, total: int, sold_on: string, created_on: string, channel: string, night_lines: list<array<string, mixed>>|null}>  $rows
     * @return array{
     *     from: string,
     *     to: string,
     *     room_nights_sold: int,
     *     room_nights_available: int,
     *     occupancy: string|null,
     *     room_revenue: int,
     *     adr: int|null,
     *     revpar: int|null,
     *     pickup_room_nights: int|null,
     *     pickup_days: int,
     *     average_length_of_stay: string|null,
     *     length_of_stay_bookings: int,
     *     lead_time_days: int|null,
     *     lead_time_bookings: int,
     *     cancellation_rate: string|null,
     *     no_show_rate: string|null,
     *     legacy_room_revenue: bool,
     *     has_activity: bool,
     *     channel_mix: list<array{channel: string, bookings: int, room_nights: int, room_revenue: int}>,
     *     nights: list<array{night: string, room_nights_sold: int, room_nights_available: int, occupancy: string|null, room_revenue: int, adr: int|null, revpar: int|null, pickup_room_nights: int}>
     * }
     */
    public static function compute(
        string $from,
        string $until,
        string $today,
        int $pickupDays,
        array $nights,
        array $inventory,
        array $rows,
        bool $channelFilter,
    ): array {
        $soldByNight = [];
        $availableByNight = [];
        $revenueByNight = [];
        $pickupByNight = [];
        $revenueNights = 0;
        $revenue = 0;
        $legacy = false;
        $pickupFrom = $pickupDays >= 1
            ? CarbonImmutable::parse($today)->subDays($pickupDays - 1)->toDateString()
            : null;

        foreach ($nights as $night) {
            $soldByNight[$night] = $inventory[$night]['sold'] ?? 0;
            $availableByNight[$night] = $inventory[$night]['available'] ?? 0;
            $revenueByNight[$night] = 0;
            $pickupByNight[$night] = 0;
        }

        if ($channelFilter) {
            foreach ($nights as $night) {
                $soldByNight[$night] = 0;
            }
        }

        $losNights = 0;
        $losBookings = 0;
        $leadDays = 0;
        $leadBookings = 0;
        $cancelledArrivals = 0;
        $noShowArrivals = 0;
        $soldArrivals = 0;
        /** @var array<string, array{channel: string, bookings: int, room_nights: int, room_revenue: int}> $mix */
        $mix = [];

        foreach ($rows as $row) {
            $status = BookingStatus::tryFrom($row['status']);
            $sold = $status instanceof BookingStatus && in_array($status, self::SOLD, true);
            $cancelled = $status instanceof BookingStatus && in_array($status, self::CANCELLED, true);
            $noShow = $status === BookingStatus::NoShow;
            $arrival = $row['check_in'] >= $from && $row['check_in'] < $until;

            if ($arrival && $sold) {
                $soldArrivals++;
                $losBookings++;
                $losNights += $row['nights'];
                $leadBookings++;
                $leadDays += self::daySpan($row['sold_on'], $row['check_in']);
            }

            if ($arrival && $cancelled) {
                $cancelledArrivals++;
            }

            if ($arrival && $noShow) {
                $noShowArrivals++;
            }

            if (! $sold) {
                continue;
            }

            $shares = self::nightRevenue($row);
            $rowNights = 0;
            $rowRevenue = 0;
            $inPickup = $pickupFrom !== null && $row['created_on'] >= $pickupFrom && $row['created_on'] <= $today;

            if ($shares['legacy']) {
                $legacy = true;
            }

            foreach ($shares['amounts'] as $night => $amount) {
                if (! isset($revenueByNight[$night])) {
                    continue;
                }

                $revenueByNight[$night] += $amount;
                $revenue += $amount;
                $revenueNights++;
                $rowNights++;
                $rowRevenue += $amount;

                if ($channelFilter) {
                    $soldByNight[$night]++;
                }

                if ($inPickup) {
                    $pickupByNight[$night]++;
                }
            }

            if ($rowNights === 0) {
                continue;
            }

            $channel = $row['channel'];

            if (! isset($mix[$channel])) {
                $mix[$channel] = [
                    'channel' => $channel,
                    'bookings' => 0,
                    'room_nights' => 0,
                    'room_revenue' => 0,
                ];
            }

            $mix[$channel]['bookings']++;
            $mix[$channel]['room_nights'] += $rowNights;
            $mix[$channel]['room_revenue'] += $rowRevenue;
        }

        $soldTotal = array_sum($soldByNight);
        $availableTotal = array_sum($availableByNight);
        $pickupTotal = $pickupFrom === null ? null : array_sum($pickupByNight);
        $arrivalBase = $soldArrivals + $cancelledArrivals + $noShowArrivals;
        $nightRows = [];

        foreach ($nights as $night) {
            $nightSold = $soldByNight[$night];
            $nightAvailable = $availableByNight[$night];
            $nightRevenue = $revenueByNight[$night];
            $nightPickup = $pickupByNight[$night];
            $adrDivisor = $channelFilter ? $nightSold : ($nightRevenue === 0 ? 0 : $nightSold);
            $nightRows[] = [
                'night' => $night,
                'room_nights_sold' => $nightSold,
                'room_nights_available' => $nightAvailable,
                'occupancy' => self::percent($nightSold, $nightAvailable),
                'room_revenue' => $nightRevenue,
                'adr' => $adrDivisor === 0 ? null : self::halfUp($nightRevenue, $adrDivisor),
                'revpar' => $nightAvailable === 0 ? null : self::halfUp($nightRevenue, $nightAvailable),
                'pickup_room_nights' => $nightPickup,
            ];
        }

        ksort($mix);

        return [
            'from' => $from,
            'to' => $until,
            'room_nights_sold' => $soldTotal,
            'room_nights_available' => $availableTotal,
            'occupancy' => self::percent($soldTotal, $availableTotal),
            'room_revenue' => $revenue,
            'adr' => $revenueNights === 0 ? null : self::halfUp($revenue, $revenueNights),
            'revpar' => $availableTotal === 0 ? null : self::halfUp($revenue, $availableTotal),
            'pickup_room_nights' => $pickupTotal,
            'pickup_days' => $pickupDays,
            'average_length_of_stay' => self::oneDecimal($losNights, $losBookings),
            'length_of_stay_bookings' => $losBookings,
            'lead_time_days' => $leadBookings === 0 ? null : self::halfUp($leadDays, $leadBookings),
            'lead_time_bookings' => $leadBookings,
            'cancellation_rate' => self::percent($cancelledArrivals, $arrivalBase),
            'no_show_rate' => self::percent($noShowArrivals, $soldArrivals + $noShowArrivals),
            'legacy_room_revenue' => $legacy,
            'has_activity' => $availableTotal > 0 || $revenueNights > 0 || $arrivalBase > 0,
            'channel_mix' => array_values($mix),
            'nights' => $nightRows,
        ];
    }

    /**
     * @param  list<string>  $nights
     * @return array<string, array{sold: int, available: int}>
     */
    private function inventory(array $nights, ?int $propertyId, ?int $roomTypeId): array
    {
        $blank = [];

        foreach ($nights as $night) {
            $blank[$night] = ['sold' => 0, 'available' => 0];
        }

        if ($nights === []) {
            return $blank;
        }

        $typeCode = null;
        $typePropertyId = null;

        if ($roomTypeId !== null) {
            $type = RoomType::query()->find($roomTypeId);

            if (! $type instanceof RoomType) {
                return $blank;
            }

            if ($propertyId !== null && $propertyId !== $type->property_id) {
                return $blank;
            }

            $typeCode = $type->code;
            $typePropertyId = $type->property_id;
        }

        $properties = Property::query()
            ->when($typePropertyId !== null, fn ($query) => $query->whereKey($typePropertyId))
            ->when($typePropertyId === null && $propertyId !== null, fn ($query) => $query->whereKey($propertyId))
            ->orderBy('id')
            ->get();

        $from = $nights[0];
        $until = CarbonImmutable::parse($nights[array_key_last($nights)])->addDay()->toDateString();

        foreach ($properties as $property) {
            foreach ($this->chunkedCounts($property, $from, $until) as $night => $byCode) {
                if (! isset($blank[$night])) {
                    continue;
                }

                foreach ($byCode as $code => $row) {
                    if ($typeCode !== null && $code !== $typeCode) {
                        continue;
                    }

                    $blank[$night]['sold'] += $row['sold'];
                    $blank[$night]['available'] += $row['free'] + $row['held'] + $row['sold'];
                }
            }
        }

        return $blank;
    }

    /**
     * @return array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>
     */
    private function chunkedCounts(Property $property, string $from, string $until): array
    {
        $merged = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($until);

        while ($cursor->lt($end)) {
            $chunkEnd = $cursor->addDays(NightAvailability::MAX_NIGHTS);

            if ($chunkEnd->gt($end)) {
                $chunkEnd = $end;
            }

            foreach ($this->availability->countsByType($property, $cursor, $chunkEnd) as $night => $byCode) {
                $merged[$night] = $byCode;
            }

            $cursor = $chunkEnd;
        }

        return $merged;
    }

    /**
     * @return list<array{status: string, check_in: string, check_out: string, nights: int, total: int, sold_on: string, created_on: string, channel: string, night_lines: list<array<string, mixed>>|null}>
     */
    private function rows(string $from, string $until, ?int $propertyId, ?int $roomTypeId, ?ChannelOfOriginGroup $channel): array
    {
        $channels = $channel instanceof ChannelOfOriginGroup
            ? ChannelOfOrigin::valuesInGroup($channel)
            : null;

        $bookings = Booking::query()
            ->whereNull('deleted_at')
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->where('check_in', '<', $until)
            ->where('check_out', '>', $from)
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->when($roomTypeId !== null, fn ($query) => $query->where('room_type_id', $roomTypeId))
            ->when($channels !== null, fn ($query) => $query->whereIn('channel_of_origin', $channels))
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($bookings as $booking) {
            $rows[] = self::row($booking);
        }

        return $rows;
    }

    /**
     * @return array{status: string, check_in: string, check_out: string, nights: int, total: int, sold_on: string, created_on: string, channel: string, night_lines: list<array<string, mixed>>|null}
     */
    private static function row(Booking $booking): array
    {
        $checkIn = self::dateString($booking->getAttributes()['check_in'] ?? null);
        $checkOut = self::dateString($booking->getAttributes()['check_out'] ?? null);
        $lines = $booking->night_lines;

        return [
            'status' => $booking->status->value,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => (int) $booking->nights,
            'total' => (int) $booking->total,
            'sold_on' => $booking->sold_on->toDateString(),
            'created_on' => SoldOn::fromTimestamp($booking->created_at),
            'channel' => $booking->channel_of_origin->value,
            'night_lines' => is_array($lines) ? array_values(array_filter($lines, is_array(...))) : null,
        ];
    }

    /**
     * @param  array{nights: int, total: int, check_in: string, night_lines: list<array<string, mixed>>|null}  $row
     * @return array{legacy: bool, amounts: array<string, int>}
     */
    private static function nightRevenue(array $row): array
    {
        $lines = $row['night_lines'];

        if (is_array($lines) && $lines !== []) {
            $amounts = [];

            foreach ($lines as $line) {
                $night = $line['night'] ?? null;
                $total = $line['total'] ?? null;

                if (! is_string($night) || ! is_numeric($total)) {
                    continue;
                }

                $amounts[$night] = ($amounts[$night] ?? 0) + (int) $total;
            }

            return ['legacy' => false, 'amounts' => $amounts];
        }

        $stayNights = $row['nights'];

        if ($stayNights < 1) {
            return ['legacy' => true, 'amounts' => []];
        }

        $shares = self::legacyShares($row['total'], $stayNights);
        $amounts = [];
        $cursor = CarbonImmutable::parse($row['check_in']);

        foreach ($shares as $share) {
            $amounts[$cursor->toDateString()] = $share;
            $cursor = $cursor->addDay();
        }

        return ['legacy' => true, 'amounts' => $amounts];
    }

    /**
     * @return list<int>
     */
    private static function legacyShares(int $total, int $nights): array
    {
        $base = intdiv($total, $nights);
        $remainder = $total % $nights;
        $shares = [];

        for ($index = 0; $index < $nights; $index++) {
            $shares[] = $base + ($index < $remainder ? 1 : 0);
        }

        return $shares;
    }

    /**
     * @return list<string>
     */
    private static function nightsBetween(string $from, string $until): array
    {
        $nights = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($until);

        while ($cursor->lt($end)) {
            $nights[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $nights;
    }

    private static function daySpan(string $from, string $to): int
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        return (int) $start->diffInDays($end, false);
    }

    private static function halfUp(int $amount, int $divisor): int
    {
        if ($divisor === 0) {
            return 0;
        }

        $negative = ($amount < 0) !== ($divisor < 0);
        $amount = abs($amount);
        $divisor = abs($divisor);
        $quotient = intdiv($amount, $divisor);
        $remainder = $amount % $divisor;

        if ($remainder * 2 >= $divisor) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    private static function percent(int $part, int $whole): ?string
    {
        if ($whole === 0) {
            return null;
        }

        $tenths = self::halfUp($part * 1000, $whole);

        return intdiv($tenths, 10).'.'.abs($tenths % 10);
    }

    private static function oneDecimal(int $part, int $whole): ?string
    {
        if ($whole === 0) {
            return null;
        }

        $tenths = self::halfUp($part * 10, $whole);

        return intdiv($tenths, 10).'.'.abs($tenths % 10);
    }

    private static function dateString(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return is_string($value) ? substr($value, 0, 10) : '';
    }
}
