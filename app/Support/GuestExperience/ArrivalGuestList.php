<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\BookingStatus;
use App\Enums\PreferenceStatus;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Iso;
use Illuminate\Database\Eloquent\Collection;

final class ArrivalGuestList
{
    /** @var list<BookingStatus> */
    private const COUNTED = [
        BookingStatus::Confirmed,
        BookingStatus::OnHoldAgency,
        BookingStatus::FullyPaid,
        BookingStatus::InHouse,
        BookingStatus::CheckedOut,
    ];

    public function __construct(private readonly CurrentConfig $config) {}

    /**
     * @return array{
     *     send_date: string,
     *     send_state: 'sent'|'scheduled',
     *     kpis: array{guests: int, bookings: int, answered: int, total: int, celebrations: int, accessibility_or_medical: int},
     *     guests: list<array<string, mixed>>
     * }
     */
    public function forArrivals(string $from, string $to, bool $sensitive): array
    {
        $days = $this->config->businessRules()->documents->preArrivalDaysBefore;
        $today = BusinessTime::now()->toDateString();
        $bookings = $this->arrivals($from, $to);
        $answered = 0;
        $celebrations = 0;
        $restricted = 0;
        $rows = [];
        $sendDates = [];

        foreach ($bookings as $booking) {
            $sendDate = $this->sendDate($booking, $days);
            $sendDates[] = $sendDate;

            foreach ($booking->guests->sortBy([['position', 'asc'], ['id', 'asc']]) as $guest) {
                $guest->setRelation('booking', $booking);
                $preference = $guest->currentPreference;
                $status = $this->status($preference, $sendDate, $today);

                if ($status === PreferenceStatus::Answered) {
                    $answered++;
                }

                if ($preference?->answer('celebr') !== null) {
                    $celebrations++;
                }

                if (trim((string) $preference?->accessibility) !== '' || trim((string) $guest->medical_note) !== '') {
                    $restricted++;
                }

                $rows[] = $this->row($guest, $preference, $status, $sendDate, $sensitive);
            }
        }

        $sendDate = $sendDates === []
            ? $this->sendDateFor($from, $days)
            : min($sendDates);
        $guests = count($rows);

        return [
            'send_date' => $sendDate,
            'send_state' => $sendDate <= $today ? 'sent' : 'scheduled',
            'kpis' => [
                'guests' => $guests,
                'bookings' => $bookings->count(),
                'answered' => $answered,
                'total' => $guests,
                'celebrations' => $celebrations,
                'accessibility_or_medical' => $restricted,
            ],
            'guests' => $rows,
        ];
    }

    /**
     * @return Collection<int, Booking>
     */
    public function arrivals(string $from, string $to): Collection
    {
        $statuses = array_map(
            fn (BookingStatus $status): string => $status->value,
            self::COUNTED,
        );

        return Booking::query()
            ->arrivingBetween($from, $to)
            ->whereIn('status', $statuses)
            ->with(['guests.currentPreference', 'room', 'property'])
            ->orderBy('check_in')
            ->orderBy('id')
            ->get();
    }

    public function sendDate(Booking $booking, ?int $days = null): string
    {
        $days ??= $this->config->businessRules()->documents->preArrivalDaysBefore;

        return $this->sendDateFor($booking->stay()->checkIn()->toDateString(), $days);
    }

    private function sendDateFor(string $checkIn, int $days): string
    {
        return BusinessTime::calendarDay($checkIn)->subDays($days)->toDateString();
    }

    private function status(?GuestPreference $preference, string $sendDate, string $today): PreferenceStatus
    {
        if ($preference instanceof GuestPreference) {
            return PreferenceStatus::Answered;
        }

        return $sendDate <= $today ? PreferenceStatus::SentNoReply : PreferenceStatus::Scheduled;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        Guest $guest,
        ?GuestPreference $preference,
        PreferenceStatus $status,
        string $sendDate,
        bool $sensitive,
    ): array {
        $email = trim((string) $guest->email);
        $row = [
            'guest_id' => $guest->id,
            'name' => $guest->displayName(),
            'booking_reference' => (string) ($guest->booking->reference ?? ''),
            'email' => $email === '' ? null : $email,
            'email_note' => $email === '' ? 'no email — sent to lead guest' : null,
            'room' => $guest->booking->roomLabel(),
            'status' => $status,
            'status_label' => $status->label(),
            'answered_at' => $preference?->answered_at === null ? null : Iso::utc($preference->answered_at),
            'source' => $preference?->source,
            'send_date' => $sendDate,
            'dietary' => $preference?->answer('diet'),
            'celebration' => $preference?->answer('celebr'),
            'activity' => $this->activity($preference),
            'accessibility_provided' => trim((string) $preference?->accessibility) !== '',
            'emergency_contact_provided' => trim((string) $preference?->emergency_contact) !== '',
        ];

        if ($sensitive) {
            $row['accessibility'] = $this->blankToNull($preference?->accessibility);
            $row['emergency_contact'] = $this->blankToNull($preference?->emergency_contact);
        }

        return $row;
    }

    private function activity(?GuestPreference $preference): ?string
    {
        $parts = array_values(array_filter([
            $preference?->answer('intensity'),
            $preference?->answer('time'),
        ], fn (?string $value): bool => $value !== null && $value !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
