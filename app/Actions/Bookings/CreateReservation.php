<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Models\User;
use App\Support\Bookings\ReservationCreated;
use Illuminate\Validation\ValidationException;

final class CreateReservation extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): ReservationCreated
    {
        throw ValidationException::withMessages([
            'stay' => ['This reservation shape has been retired.'],
        ]);
    }
}
