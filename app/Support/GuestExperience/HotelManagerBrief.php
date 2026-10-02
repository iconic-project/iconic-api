<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Models\Departure;
use App\Models\GuestPreference;
use App\Services\Documents\DocumentFonts;
use App\Services\Documents\PdfRenderer;
use App\Support\Manifests\ManifestPassenger;
use App\Support\Manifests\ManifestRoster;
use Illuminate\Support\Collection;

final class HotelManagerBrief
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    public function html(Departure $departure, bool $sensitive): string
    {
        $departure->loadMissing(['property', 'itinerary']);
        $passengers = ManifestRoster::passengers($departure);
        $answered = $passengers->filter(
            fn (ManifestPassenger $passenger): bool => $passenger->guest->currentPreference instanceof GuestPreference,
        );

        $html = view('guest-experience.brief', [
            'property' => $departure->property->name,
            'departureDate' => $departure->date->format('j M Y'),
            'guests' => $passengers->count(),
            'answered' => $answered->count(),
            'sections' => $this->sections($answered, $sensitive),
            'counts' => $this->counts($answered),
            'unanswered' => $passengers->count() - $answered->count(),
        ])->render();

        return DocumentFonts::embed($html);
    }

    public function pdf(Departure $departure, bool $sensitive): string
    {
        return $this->pdf->render($this->html($departure, $sensitive));
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $answered
     * @return list<array{label: string, rows: list<array{who: string, value: string}>}>
     */
    private function sections(Collection $answered, bool $sensitive): array
    {
        $sections = [
            $this->section('Dietary & food preferences', 'diet', $answered),
            $this->section('Celebrations on board', 'celebr', $answered),
        ];

        if ($sensitive) {
            $sections[] = $this->restrictedSection($answered);
        }

        $sections[] = $this->section('Special requests', 'req', $answered);

        return $sections;
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $answered
     * @return array{label: string, rows: list<array{who: string, value: string}>}
     */
    private function section(string $label, string $key, Collection $answered): array
    {
        $rows = [];

        foreach ($answered as $passenger) {
            $value = $passenger->guest->currentPreference?->answer($key);

            if ($value === null) {
                continue;
            }

            $rows[] = [
                'who' => $passenger->passengerName().' · '.$passenger->cabinLabel(),
                'value' => $value,
            ];
        }

        return ['label' => $label, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $answered
     * @return array{label: string, rows: list<array{who: string, value: string}>}
     */
    private function restrictedSection(Collection $answered): array
    {
        $rows = [];

        foreach ($answered as $passenger) {
            $value = trim((string) $passenger->guest->currentPreference?->accessibility);

            if ($value === '') {
                continue;
            }

            $rows[] = [
                'who' => $passenger->passengerName().' · '.$passenger->cabinLabel(),
                'value' => $value,
            ];
        }

        return ['label' => 'Accessibility requirements', 'rows' => $rows];
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $answered
     * @return array<string, string>
     */
    private function counts(Collection $answered): array
    {
        return [
            'Pillows' => $this->tally($answered, 'pillow'),
            'Cabin temperature' => $this->tally($answered, 'temp'),
            'Breakfast' => $this->tally($answered, 'breakfast'),
            'Activity intensity' => $this->tally($answered, 'intensity'),
            'Activity time' => $this->tally($answered, 'time'),
            'First time in Galápagos' => $this->tally($answered, 'first'),
        ];
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $answered
     */
    private function tally(Collection $answered, string $key): string
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($answered as $passenger) {
            $value = $passenger->guest->currentPreference?->answer($key);

            if ($value === null) {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        if ($counts === []) {
            return '—';
        }

        $parts = [];

        foreach ($counts as $value => $count) {
            $parts[] = $value.' × '.$count;
        }

        return implode(' · ', $parts);
    }
}
