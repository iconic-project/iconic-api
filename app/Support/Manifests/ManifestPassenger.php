<?php

declare(strict_types=1);

namespace App\Support\Manifests;

use App\Enums\BookingType;
use App\Enums\ManifestKind;
use App\Models\Departure;
use App\Models\Guest;
use App\Support\Countries;
use App\Support\Guests\Age;
use Carbon\CarbonImmutable;

final readonly class ManifestPassenger
{
    public function __construct(
        public Guest $guest,
        public int $number,
        public Departure $departure,
    ) {}

    public function complete(): bool
    {
        return $this->guest->isComplete();
    }

    public function cabinLabel(): string
    {
        $booking = $this->guest->booking;

        if ($booking->type === BookingType::Charter && $booking->cabin_id === null) {
            return 'Full property';
        }

        return $booking->cabin->label;
    }

    /**
     * @return list<string>
     */
    public function dpngCells(): array
    {
        $guest = $this->guest;

        return [
            (string) $this->number,
            $this->blank($guest->last_name),
            $this->blank($guest->first_name),
            $this->nationalityName(),
            $this->passport(),
            $this->date($guest->passport_expiry),
            $this->date($guest->dob),
            $this->age(),
            $this->cabinLabel(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function dpngHeaders(): array
    {
        return ['#', 'Surname', 'Given names', 'Nationality', 'Passport', 'Expiry', 'DOB', 'Age at departure', 'Cabin'];
    }

    /**
     * @return list<string>
     */
    public function captainCells(): array
    {
        $guest = $this->guest;
        $notes = CaptainParticulars::for($guest);
        $name = $guest->displayName();

        if ($guest->is_lead) {
            $name .= ' (lead)';
        }

        return [
            $this->cabinLabel(),
            $name,
            $this->blank($guest->nationality),
            $this->age(),
            $this->passport(),
            $notes['emergency'],
            $notes['dietary'],
            $notes['medical'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function captainHeaders(): array
    {
        return ['Cabin', 'Passenger', 'Nat.', 'Age', 'Passport', 'Emergency contact', 'Dietary', 'Medical / accessibility'];
    }

    /**
     * Fields that count for this kind's snapshot hash.
     *
     * @return array<string, mixed>
     */
    public function hashPayload(ManifestKind $kind): array
    {
        $payload = [
            'guest_id' => $this->guest->id,
            'booking_id' => $this->guest->booking_id,
            'position' => $this->guest->position,
            'cells' => $kind === ManifestKind::Dpng ? $this->dpngCells() : $this->captainCells(),
        ];

        if ($kind === ManifestKind::Captain) {
            $payload['reference'] = $this->guest->booking->reference;
            $payload['lead'] = $this->guest->is_lead;
        }

        return $payload;
    }

    public function passengerName(): string
    {
        $name = $this->guest->displayName();

        return $this->guest->is_lead ? $name.' (lead)' : $name;
    }

    private function nationalityName(): string
    {
        $code = trim((string) $this->guest->nationality);

        return $code === '' ? '—' : Countries::name($code);
    }

    private function passport(): string
    {
        $number = trim((string) $this->guest->passport_no);

        return $number === '' ? 'MISSING' : $number;
    }

    private function age(): string
    {
        $age = Age::at($this->guest->dob, CarbonImmutable::parse($this->departure->date->toDateString()));

        return $age === null ? '—' : (string) $age;
    }

    private function date(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('j M Y');
        }

        return '—';
    }

    private function blank(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? '—' : $trimmed;
    }
}
