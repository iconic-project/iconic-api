<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class CreateBookingRequest extends Action
{
    public function __construct(
        private CreateStayReservation $stays,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?CarbonInterface $referenceAt = null): Booking
    {
        if (isset($data['check_in']) && is_string($data['check_in']) && $data['check_in'] !== '') {
            return $this->stays->request($data, $actor, $referenceAt);
        }

        throw ValidationException::withMessages([
            'stay' => ['This reservation shape has been retired.'],
        ]);
    }
}
