<?php

declare(strict_types=1);

namespace App\Services\Config;

use App\Enums\ConfigKind;
use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use App\Models\StayRestriction;
use App\Support\BusinessTime;
use App\Support\Config\Documents\Rates\Season;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Config\Warning;

/**
 * Soft checks for a stay rates document. Shown on the Rates page with the document warnings.
 */
final class StayConfigChecks
{
    /**
     * @return list<Warning>
     */
    public function forDocument(RatesDocument $document): array
    {
        return [
            ...$this->roomRateWarnings($document),
            ...$this->seasonGapWarnings($document),
            ...$this->restrictionWarnings($document),
        ];
    }

    /**
     * @return list<Warning>
     */
    private function roomRateWarnings(RatesDocument $document): array
    {
        if ($document->seasons === []) {
            return [];
        }

        $warnings = [];
        $types = RoomType::query()
            ->where('status', RoomTypeStatus::Active)
            ->orderBy('sort')
            ->orderBy('code')
            ->get();

        foreach ($types as $type) {
            foreach ($document->seasons as $season) {
                if ($this->rated($document, $type->code, $season->code)) {
                    continue;
                }

                $warnings[] = new Warning(
                    'room_rates',
                    $type->name.' ('.$type->code.') has no rate in '.$season->name.' ('.$season->code.').',
                );
            }
        }

        return $warnings;
    }

    /**
     * @return list<Warning>
     */
    private function seasonGapWarnings(RatesDocument $document): array
    {
        $current = app(CurrentConfig::class);

        if (! $current->has(ConfigKind::EngineSettings)) {
            return [];
        }

        $months = $current->engineSettings()->calendar->horizonMonths;

        if ($months < 1) {
            return [];
        }

        $start = BusinessTime::now()->startOfDay();
        $end = $start->addMonths($months)->subDay();
        $warnings = [];
        $gapFrom = null;
        $gapTo = null;

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();

            if ($this->seasonOn($document, $date) === null) {
                $gapFrom ??= $date;
                $gapTo = $date;

                continue;
            }

            if ($gapFrom !== null && $gapTo !== null) {
                $warnings[] = new Warning(
                    'seasons',
                    'Nights from '.$gapFrom.' to '.$gapTo.' are outside every season.',
                );
                $gapFrom = null;
                $gapTo = null;
            }
        }

        if ($gapFrom !== null && $gapTo !== null) {
            $warnings[] = new Warning(
                'seasons',
                'Nights from '.$gapFrom.' to '.$gapTo.' are outside every season.',
            );
        }

        return $warnings;
    }

    /**
     * @return list<Warning>
     */
    private function restrictionWarnings(RatesDocument $document): array
    {
        $warnings = [];
        $rows = StayRestriction::query()->with('roomType')->orderBy('night')->orderBy('id')->get();

        foreach ($rows as $row) {
            $date = $row->night->toDateString();
            $season = $this->seasonOn($document, $date);
            $type = $row->roomType;

            if (! $season instanceof Season) {
                $unrated = true;
            } elseif ($type instanceof RoomType) {
                $unrated = ! $this->rated($document, $type->code, $season->code);
            } else {
                $unrated = ! $this->anyRate($document, $season->code);
            }

            if (! $unrated) {
                continue;
            }

            $who = $type instanceof RoomType ? ' for '.$type->code : '';
            $warnings[] = new Warning(
                'stay_restrictions',
                'A restriction on '.$date.$who.' sits on a night with no rate.',
            );
        }

        return $warnings;
    }

    private function seasonOn(RatesDocument $document, string $date): ?Season
    {
        foreach ($document->seasons as $season) {
            if ($season->contains($date)) {
                return $season;
            }
        }

        return null;
    }

    private function rated(RatesDocument $document, string $roomType, string $season): bool
    {
        foreach ($document->roomRates as $rate) {
            if ($rate->roomType === $roomType && $rate->season === $season) {
                return true;
            }
        }

        return false;
    }

    private function anyRate(RatesDocument $document, string $season): bool
    {
        foreach ($document->roomRates as $rate) {
            if ($rate->season === $season) {
                return true;
            }
        }

        return false;
    }
}
