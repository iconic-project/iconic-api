<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MoveBooking extends Action
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     available: bool,
     *     current_total: int,
     *     new_total: int,
     *     difference: int,
     *     new_price_lines: list<array{code: string, label: string, amount: int}>,
     *     sailing_year_changes: bool,
     *     festive_changes: bool,
     *     warnings: list<string>
     * }
     */
    public function preview(Booking $booking, array $data): array
    {
        throw ValidationException::withMessages([
            'stay' => ['This reservation shape has been retired.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        throw ValidationException::withMessages([
            'stay' => ['This reservation shape has been retired.'],
        ]);
    }
}
