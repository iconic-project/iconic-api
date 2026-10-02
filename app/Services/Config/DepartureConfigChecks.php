<?php

declare(strict_types=1);

namespace App\Services\Config;

use App\Enums\DepartureStatus;
use App\Enums\EngineLabelCode;
use App\Models\Departure;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Config\Warning;
use App\Support\Dates\Format;
use App\Support\Inventory\Snapshots;
use Carbon\CarbonImmutable;

final class DepartureConfigChecks
{
    public const OPS_006_FIRST_CRUISE = '2027-11-07';

    /**
     * @return array<string, list<string>>
     */
    public function rateYearErrors(RatesDocument $draft, ?RatesDocument $published): array
    {
        if (! $published instanceof RatesDocument) {
            return [];
        }

        $draftYears = [];

        foreach ($draft->years as $year) {
            $draftYears[$year->year] = true;
        }

        $counts = $this->departureCountsByYear();
        $messages = [];

        foreach ($published->years as $year) {
            if (isset($draftYears[$year->year])) {
                continue;
            }

            $count = $counts[$year->year] ?? 0;

            if ($count === 0) {
                continue;
            }

            $noun = $count === 1 ? 'departure' : 'departures';
            $messages[] = "Can't remove {$year->year} — {$count} {$noun} sail that year.";
        }

        return $messages === [] ? [] : ['years' => $messages];
    }

    /**
     * @return list<Warning>
     */
    public function rateYearWarnings(RatesDocument $draft): array
    {
        $draftYears = [];

        foreach ($draft->years as $year) {
            $draftYears[$year->year] = true;
        }

        $warnings = [];

        foreach ($this->departureCountsByYear() as $year => $count) {
            if ($count === 0 || isset($draftYears[$year])) {
                continue;
            }

            $warnings[] = new Warning('years', "Departures in {$year} have no rates.");
        }

        return $warnings;
    }

    /**
     * @return list<Warning>
     */
    public function engineSearchWarnings(EngineSettingsDocument $draft): array
    {
        $from = $draft->calendar->defaultSearchFrom;
        $first = $this->firstBookableMonth();

        if ($first === null || $from === '' || $from >= $first) {
            return [];
        }

        $label = CarbonImmutable::createFromFormat('!Y-m', $first)?->format('M Y') ?? $first;

        return [
            new Warning(
                'calendar.default_search_from',
                'Default search starts before the first bookable month ('.$label.') — guests would open on empty months.',
            ),
        ];
    }

    /**
     * @return array{display: string, differs: bool|null}
     */
    public function ops006Current(): array
    {
        $earliest = Departure::query()->min('date');

        if (! is_string($earliest) || $earliest === '') {
            return [
                'display' => 'No departures yet',
                'differs' => null,
            ];
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', substr($earliest, 0, 10));
        $date = $parsed instanceof CarbonImmutable ? $parsed : CarbonImmutable::parse($earliest);

        return [
            'display' => 'First cruise '.Format::calendar($date),
            'differs' => substr($earliest, 0, 10) !== self::OPS_006_FIRST_CRUISE,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function departureCountsByYear(): array
    {
        $rows = Departure::query()
            ->selectRaw('YEAR(`date`) as year, COUNT(*) as aggregate')
            ->groupByRaw('YEAR(`date`)')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('year')] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    public function firstBookableMonth(): ?string
    {
        $departures = Departure::query()
            ->with(['property.cabins', 'itinerary'])
            ->where('status', DepartureStatus::OnSale)
            ->orderBy('date')
            ->get();

        if ($departures->isEmpty()) {
            return null;
        }

        $snapshots = Snapshots::attach($departures);
        $hidden = [EngineLabelCode::NotShown->value, EngineLabelCode::Chartered->value];

        foreach ($departures as $departure) {
            $code = $snapshots[$departure->id]->engineLabel['code'] ?? null;

            if (! in_array($code, $hidden, true)) {
                return $departure->date->format('Y-m');
            }
        }

        return null;
    }
}
