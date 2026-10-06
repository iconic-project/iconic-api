<?php

declare(strict_types=1);

namespace App\Support\Guests;

use App\Enums\BookingType;
use App\Enums\ConsentDocument;
use App\Enums\RegistrationField;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Dates\Format;
use Carbon\CarbonImmutable;

final class GuestIssues
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * @return list<array{severity: string, code: string, guest_id: int|null, message: string}>
     */
    public function for(Booking $booking): array
    {
        $booking->loadMissing(['guests', 'consents']);
        $settings = $this->config->engineSettings()->guests;
        $checkIn = $booking->stay()->checkIn();
        $checkOut = $booking->stay()->checkOut();
        $issues = [];
        $registrationSeverity = $this->registrationSeverity($checkIn);

        foreach ($booking->guests as $guest) {
            if ($guest->first_name === '' && $guest->last_name === '') {
                continue;
            }

            $name = $guest->displayName();
            $age = Age::at($guest->dob, $checkIn);

            if ($age !== null && $age < $settings->childMinAge) {
                $issues[] = $this->issue(
                    'error',
                    'under_min_age',
                    $guest->id,
                    $name.' is '.$age.' on check-in — minimum age is '.$settings->childMinAge.' (OPS-004).',
                );
            }

            if ($guest->dob !== null && Age::isMinorNow($guest->dob) && $guest->guardian_consented_at === null) {
                $issues[] = $this->issue(
                    'error',
                    'guardian_consent_missing',
                    $guest->id,
                    $name.' is under 18 — guardian consent required (§6.4).',
                );
            }

            if ($guest->passport_expiry !== null && $guest->passport_expiry->toDateString() < $checkOut->toDateString()) {
                $issues[] = $this->issue(
                    'error',
                    'passport_expired',
                    $guest->id,
                    $name."'s passport expires before check-out (".Format::calendar($checkOut).').',
                );
            }

            if ($guest->first_name !== '' && ! $guest->insurance_declared && $booking->status->isConfirmedOrLater()) {
                $issues[] = $this->issue(
                    'warning',
                    'insurance_undeclared',
                    $guest->id,
                    $name.' has no travel-insurance declaration (OPS-005).',
                );
            }

            foreach ($this->registrationFields() as $field) {
                if (! $field->onGuest() || $field->present($guest, $booking)) {
                    continue;
                }

                $issues[] = $this->issue(
                    $registrationSeverity,
                    'registration_missing',
                    $guest->id,
                    $name.' is missing '.$field->label().' for guest registration.',
                );
            }
        }

        foreach ($this->registrationFields() as $field) {
            if ($field->onGuest() || $field->presentOnBooking($booking)) {
                continue;
            }

            $issues[] = $this->issue(
                $registrationSeverity,
                'registration_missing',
                null,
                ucfirst($field->label()).' is missing for guest registration.',
            );
        }

        if ($booking->type === BookingType::Room) {
            $guests = $booking->guests;
            $dated = $guests->filter(fn (Guest $guest): bool => $guest->dob !== null)->count();

            if ($guests->isNotEmpty() && $dated === $guests->count()) {
                $kids = $guests->filter(function (Guest $guest) use ($checkIn, $settings): bool {
                    $age = Age::at($guest->dob, $checkIn);

                    return $age !== null
                        && $age >= $settings->childMinAge
                        && $age <= $settings->childMaxAge;
                })->count();

                if ($kids !== $booking->children) {
                    $issues[] = $this->issue(
                        'warning',
                        'children_mismatch',
                        null,
                        'Guests aged '.$settings->childMinAge.'–'.$settings->childMaxAge.': '.$kids
                            .' · priced as children: '.$booking->children.' — check the quote.',
                    );
                }
            }
        }

        if ($booking->status->isConfirmedOrLater()) {
            $accepted = $booking->consents
                ->filter(fn (Consent $consent): bool => ! $consent->withdrawn)
                ->groupBy(fn (Consent $consent): string => $consent->document->value)
                ->map(fn ($rows): ?Consent => $rows->sortByDesc('id')->first());

            $missing = [];

            foreach (ConsentDocument::cases() as $document) {
                if (! $document->required()) {
                    continue;
                }

                if (! $accepted->get($document->value) instanceof Consent) {
                    $missing[] = $document->label();
                }
            }

            if ($missing !== []) {
                $issues[] = $this->issue(
                    'warning',
                    'consents_missing',
                    null,
                    'Missing consent records: '.implode(', ', $missing).'.',
                );
            }
        }

        return $issues;
    }

    /**
     * @return array{complete_count: int, total: int, png_known_total: int, png_pending_count: int, max: int, can_add: bool}
     */
    public function summary(Booking $booking): array
    {
        $booking->loadMissing('guests');
        $guests = $booking->guests;
        $total = $guests->count();
        $booking->loadMissing('roomType');
        $max = GuestCapacity::max($booking->type, $this->config->engineSettings()->guests, $booking->roomType);
        $guestFields = array_values(array_filter(
            $this->registrationFields(),
            fn (RegistrationField $field): bool => $field->onGuest(),
        ));

        return [
            'complete_count' => $guests->filter(function (Guest $guest) use ($guestFields, $booking): bool {
                if ($guest->first_name === '' && $guest->last_name === '') {
                    return false;
                }

                foreach ($guestFields as $field) {
                    if (! $field->present($guest, $booking)) {
                        return false;
                    }
                }

                return true;
            })->count(),
            'total' => $total,
            'png_known_total' => (int) $guests->sum(fn (Guest $guest): int => $guest->png_fee ?? 0),
            'png_pending_count' => 0,
            'max' => $max,
            'can_add' => $total < $max,
        ];
    }

    /**
     * @return list<RegistrationField>
     */
    private function registrationFields(): array
    {
        return $this->config->businessRules()->registration->fieldEnums();
    }

    private function registrationSeverity(CarbonImmutable $checkIn): string
    {
        $hours = $this->config->businessRules()->registration->deadlineHoursAfterCheckIn;

        if ($hours === null) {
            return 'warning';
        }

        $deadline = CarbonImmutable::parse($checkIn->toDateString(), BusinessTime::zone())->startOfDay()->addHours($hours);

        return BusinessTime::now()->greaterThan($deadline) ? 'error' : 'warning';
    }

    /**
     * @return array{severity: string, code: string, guest_id: int|null, message: string}
     */
    private function issue(string $severity, string $code, ?int $guestId, string $message): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'guest_id' => $guestId,
            'message' => $message,
        ];
    }
}
