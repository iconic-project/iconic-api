<?php

declare(strict_types=1);

namespace App\Support\Registration;

use App\Enums\RegistrationField;
use App\Models\Booking;
use App\Models\Guest;
use Illuminate\Support\Collection;

final class RegistrationSheet
{
    /**
     * @param  list<RegistrationField>  $fields
     * @param  Collection<int, Booking>  $bookings
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public static function build(array $fields, Collection $bookings): array
    {
        $headers = array_map(
            fn (RegistrationField $field): string => $field->value,
            $fields,
        );
        $rows = [];

        foreach ($bookings as $booking) {
            $guests = $booking->guests
                ->filter(fn (Guest $guest): bool => $guest->first_name !== '' || $guest->last_name !== '')
                ->sortBy('position');

            foreach ($guests as $guest) {
                $rows[] = array_map(
                    fn (RegistrationField $field): string => $field->cell($guest, $booking),
                    $fields,
                );
            }
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
}
