<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Guests\ApplyPng;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoGuestsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $png = app(ApplyPng::class);
        $settings = app(CurrentConfig::class)->engineSettings();
        $maxPerProperty = $settings->guests->maxPerProperty;

        DB::transaction(function () use ($png, $maxPerProperty): void {
            foreach ($this->passengers() as $reference => $rows) {
                $booking = Booking::query()
                    ->with('departure')
                    ->where(function ($query) use ($reference): void {
                        $query->where('reference', $reference)
                            ->orWhere('request_reference', $reference);
                    })
                    ->first();

                if (! $booking instanceof Booking) {
                    continue;
                }

                $want = $booking->type === BookingType::Charter
                    ? $maxPerProperty
                    : max(1, $booking->adults + $booking->children);

                while (count($rows) < $want) {
                    $rows[] = [];
                }

                $existing = Guest::query()
                    ->where('booking_id', $booking->id)
                    ->get()
                    ->keyBy('position');

                foreach ($rows as $index => $row) {
                    $position = $index + 1;
                    $guest = $existing->get($position) ?? new Guest;
                    $guest->booking_id = $booking->id;
                    $guest->position = $position;
                    $guest->is_lead = $position === 1;
                    $guest->first_name = $row['first_name'] ?? '';
                    $guest->last_name = $row['last_name'] ?? '';
                    $guest->dob = $row['dob'] ?? null;
                    $guest->nationality = $row['nationality'] ?? null;
                    $guest->ecuador_resident = (bool) ($row['ecuador_resident'] ?? false);
                    $guest->passport_no = $row['passport_no'] ?? null;
                    $guest->passport_expiry = $row['passport_expiry'] ?? null;
                    $guest->email = $row['email'] ?? null;
                    $guest->insurance_declared = (bool) ($row['insurance_declared'] ?? false);
                    $guest->medical_note = $row['medical_note'] ?? null;
                    $guest->dietary_note = $row['dietary_note'] ?? null;
                    $guest->accessibility_note = $row['accessibility_note'] ?? null;
                    $guest->guardian_name = $row['guardian_name'] ?? null;
                    $guest->guardian_relationship = $row['guardian_relationship'] ?? null;
                    $guest->guardian_consented_at = $row['guardian_consented_at'] ?? null;
                    $guest->guardian_recorded_by = $row['guardian_recorded_by'] ?? null;
                    $png->toGuest($guest, $booking);
                    $guest->save();
                }

                Guest::query()
                    ->where('booking_id', $booking->id)
                    ->where('position', '>', $want)
                    ->delete();
            }
        });
    }

    /**
     * Prototype `seedOps` passengers. Empty slots are padded to the priced party
     * (or `guests.max_per_property` for a charter, whose priced party is 0 adults).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function passengers(): array
    {
        $leonConsent = CarbonImmutable::create(2026, 7, 2, 14, 5, 0, BusinessTime::zone());

        return [
            'ANK-2026-0003' => [
                $this->row('Daniel', 'Harrison', '1971-04-12', 'US', email: 'd.harrison@—.com'),
                $this->row('Claire', 'Whitfield', '1974-09-30', 'US'),
            ],
            'ANK-2026-0005' => [
                $this->row('Markus', 'Brandt', '1979-02-14', 'DE', 'C4F7K2L9M', '2031-05-01', true, 'm.brandt@—.de'),
                $this->row('Julia', 'Brandt', '1981-06-03', 'DE', 'C4F7K8Q1R', '2031-05-01', true),
                $this->row(
                    'Leon',
                    'Brandt',
                    '2015-03-02',
                    'DE',
                    'C4F9T3W6Z',
                    '2029-08-15',
                    true,
                    guardianName: 'Markus Brandt',
                    guardianRelationship: 'Father',
                    guardianConsentedAt: $leonConsent,
                ),
            ],
            'ANK-2026-0007' => [
                $this->row('Mariana', 'Castellanos', '1985-11-20', 'CO', 'AX1234567', '2030-02-01', true),
            ],
            'ANK-2026-0009' => [
                $this->row('Erik', 'Söderberg', '1968-01-09', 'SE', '91827364', '2032-03-10', true),
                $this->row('Anna', 'Söderberg', '1970-07-22', 'SE', '91827365', '2032-03-10', true),
            ],
            'ANK-2026-0011' => [
                $this->row('Joseph', 'Okafor', '1976-05-05', 'GB', '548213977', '2033-01-20', true),
                $this->row('Precious', 'Okafor', '1978-12-11', 'GB'),
            ],
            'ANK-2026-0012' => [
                $this->row('Rutger', 'Vandermeer', '1965-08-30', 'NL'),
            ],
            'ANK-2026-0014' => [
                $this->row('Robert', 'Ellison', '1969-03-15', 'US'),
            ],
            'ANK-2026-0016' => [
                $this->row('Lorena', 'Alvear', '1972-10-02', 'AR', 'AAG512877', '2031-11-01', true),
                $this->row('Martín', 'Alvear', '1970-04-18', 'AR', 'AAG512878', '2031-11-01', true),
            ],
            'ANK-2026-0017' => [
                $this->row('Sofía', 'Ruiz', '1990-06-25', 'AR', 'AAH113209', '2030-06-01', true),
                $this->row('Tomás', 'Ruiz', '1989-09-14', 'AR'),
            ],
            'ANK-2026-0019' => [
                $this->row('Diego', 'Pereyra', '1983-02-27', 'EC', 'A0977123', '2029-12-12', true),
                $this->row('Ana', 'Pereyra', '1985-05-19', 'EC'),
            ],
            'ANK-2026-0018' => [
                $this->row('Amélie', 'Fontaine', '1980-03-08', 'FR', '19FH55012', '2030-10-01', true),
                $this->row('Paul', 'Fontaine', '1978-11-29', 'FR', '19FH55013', '2030-10-01', true),
            ],
            'ANK-R-2026-0041' => [
                $this->row('Emma', 'Harmon', '1975-01-17', 'US'),
            ],
            'ANK-R-2026-0042' => [
                $this->row('Louise', 'Moreau', '1984-08-03', 'FR'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $first,
        string $last,
        string $dob,
        string $nationality,
        ?string $passport = null,
        ?string $expiry = null,
        bool $insurance = false,
        ?string $email = null,
        ?string $guardianName = null,
        ?string $guardianRelationship = null,
        ?CarbonImmutable $guardianConsentedAt = null,
    ): array {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'dob' => $dob,
            'nationality' => $nationality,
            'passport_no' => $passport,
            'passport_expiry' => $expiry,
            'insurance_declared' => $insurance,
            'email' => $email,
            'guardian_name' => $guardianName,
            'guardian_relationship' => $guardianRelationship,
            'guardian_consented_at' => $guardianConsentedAt,
        ];
    }
}
