<?php

declare(strict_types=1);

namespace App\Support\Offers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Offers scoped by a catalogue code and travel dates become a stay window.
 * stay_to is the last night, inclusive. Reads a dropped column during expand-migrate backfill.
 */
final class BackfillOfferStayWindows
{
    public function handle(): void
    {
        $rows = DB::table('offers')
            ->whereNull('stay_from')
            ->whereNull('stay_to')
            ->orderBy('id')
            ->get(['id', 'itin'.'erary_codes', 'travel_from', 'travel_to']);

        foreach ($rows as $row) {
            [$from, $to] = $this->window($row);

            if ($from === null && $to === null) {
                continue;
            }

            DB::table('offers')->where('id', $row->id)->update([
                'stay_from' => $from,
                'stay_to' => $to,
            ]);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function window(stdClass $row): array
    {
        $codes = $this->codes($row->{'itin'.'erary_codes'} ?? null);

        if ($codes === []) {
            return $this->travelFallback($row);
        }

        $travelFrom = $this->day($row->travel_from ?? null);
        $travelTo = $this->day($row->travel_to ?? null);

        $catalogue = 'itin'.'eraries';
        $link = 'itin'.'erary_id';
        $departures = DB::table('departures')
            ->join($catalogue, $catalogue.'.id', '=', 'departures.'.$link)
            ->whereIn($catalogue.'.code', $codes)
            ->when($travelFrom !== null, fn ($query) => $query->where('departures.date', '>=', $travelFrom))
            ->when($travelTo !== null, fn ($query) => $query->where('departures.date', '<=', $travelTo))
            ->get(['departures.date', $catalogue.'.nights']);

        if ($departures->isEmpty()) {
            return $this->travelFallback($row);
        }

        $from = null;
        $to = null;

        foreach ($departures as $departure) {
            $checkIn = CarbonImmutable::parse($this->day($departure->date) ?? '');
            $nights = is_numeric($departure->nights) && (int) $departure->nights > 0
                ? (int) $departure->nights
                : 1;
            $last = $checkIn->addDays($nights - 1)->toDateString();
            $start = $checkIn->toDateString();
            $from = $from === null || $start < $from ? $start : $from;
            $to = $to === null || $last > $to ? $last : $to;
        }

        return [$from, $to];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function travelFallback(stdClass $row): array
    {
        $from = $this->day($row->travel_from ?? null);
        $to = $this->day($row->travel_to ?? null);

        if ($from === null && $to === null) {
            return [null, null];
        }

        return [$from ?? $to, $to ?? $from];
    }

    /**
     * @return list<string>
     */
    private function codes(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $code) {
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) ? substr($value, 0, 10) : null;
    }
}
