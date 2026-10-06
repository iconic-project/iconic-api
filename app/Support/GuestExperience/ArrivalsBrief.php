<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Services\Config\CurrentConfig;
use App\Services\Documents\DocumentFonts;
use App\Services\Documents\PdfRenderer;
use Illuminate\Support\Collection;

final class ArrivalsBrief
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly DepartureGuestExperience $experience,
        private readonly CurrentConfig $config,
    ) {}

    public function html(string $date, bool $sensitive): string
    {
        $bookings = $this->experience->arrivals($date, $date);
        $guests = $this->named($bookings);
        $answered = $guests->filter(
            fn (Guest $guest): bool => $guest->currentPreference instanceof GuestPreference,
        );

        $html = view('guest-experience.brief', [
            'property' => $bookings->map(fn (Booking $booking): string => $booking->property->name)->unique()->implode(', '),
            'arrivalDate' => $date,
            'guests' => $guests->count(),
            'answered' => $answered->count(),
            'sections' => $this->sections($guests, $answered, $sensitive),
            'counts' => $this->counts($answered),
            'unanswered' => $guests->count() - $answered->count(),
        ])->render();

        return DocumentFonts::embed($html);
    }

    public function pdf(string $date, bool $sensitive): string
    {
        return $this->pdf->render($this->html($date, $sensitive));
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Guest>
     */
    private function named(Collection $bookings): Collection
    {
        $guests = collect();

        foreach ($bookings as $booking) {
            foreach ($booking->guests as $guest) {
                $guest->setRelation('booking', $booking);

                if (trim($guest->first_name.$guest->last_name) === '') {
                    continue;
                }

                $guests->push($guest);
            }
        }

        return $guests;
    }

    /**
     * @param  Collection<int, Guest>  $guests
     * @param  Collection<int, Guest>  $answered
     * @return list<array{label: string, rows: list<array{who: string, value: string}>}>
     */
    private function sections(Collection $guests, Collection $answered, bool $sensitive): array
    {
        $sections = [
            $this->arrivals($guests),
            $this->section('Dietary & food preferences', 'diet', $answered),
            $this->section('Celebrations', 'celebr', $answered),
        ];

        if ($sensitive) {
            $sections[] = $this->restrictedSection($answered);
        }

        $sections[] = $this->section('Special requests', 'req', $answered);

        return $sections;
    }

    /**
     * @param  Collection<int, Guest>  $guests
     * @return array{label: string, rows: list<array{who: string, value: string}>}
     */
    private function arrivals(Collection $guests): array
    {
        $rows = [];

        foreach ($guests as $guest) {
            $rows[] = [
                'who' => $this->who($guest),
                'value' => $this->arrivalTime($guest->booking),
            ];
        }

        return ['label' => 'Expected arrival', 'rows' => $rows];
    }

    /**
     * @param  Collection<int, Guest>  $answered
     * @return array{label: string, rows: list<array{who: string, value: string}>}
     */
    private function section(string $label, string $key, Collection $answered): array
    {
        $rows = [];

        foreach ($answered as $guest) {
            $value = $guest->currentPreference?->answer($key);

            if ($value === null) {
                continue;
            }

            $rows[] = [
                'who' => $this->who($guest),
                'value' => $value,
            ];
        }

        return ['label' => $label, 'rows' => $rows];
    }

    /**
     * @param  Collection<int, Guest>  $answered
     * @return array{label: string, rows: list<array{who: string, value: string}>}
     */
    private function restrictedSection(Collection $answered): array
    {
        $rows = [];

        foreach ($answered as $guest) {
            $value = trim((string) $guest->currentPreference?->accessibility);

            if ($value === '') {
                continue;
            }

            $rows[] = [
                'who' => $this->who($guest),
                'value' => $value,
            ];
        }

        return ['label' => 'Accessibility requirements', 'rows' => $rows];
    }

    /**
     * @param  Collection<int, Guest>  $answered
     * @return array<string, string>
     */
    private function counts(Collection $answered): array
    {
        return [
            'Pillows' => $this->tally($answered, 'pillow'),
            'Room temperature' => $this->tally($answered, 'temp'),
            'Breakfast' => $this->tally($answered, 'breakfast'),
            'Activity intensity' => $this->tally($answered, 'intensity'),
            'Activity time' => $this->tally($answered, 'time'),
            'First time in Galápagos' => $this->tally($answered, 'first'),
        ];
    }

    /**
     * @param  Collection<int, Guest>  $answered
     */
    private function tally(Collection $answered, string $key): string
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($answered as $guest) {
            $value = $guest->currentPreference?->answer($key);

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

    private function who(Guest $guest): string
    {
        $place = GuestCabin::label($guest);

        return $place === '' ? $guest->displayName() : $guest->displayName().' · '.$place;
    }

    private function arrivalTime(Booking $booking): string
    {
        $expected = trim((string) $booking->expected_arrival_time);

        if ($expected !== '') {
            return substr($expected, 0, 5);
        }

        return $this->config->businessRules()->stay->checkInTime;
    }
}
