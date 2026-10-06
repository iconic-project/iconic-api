<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Actions\Action;
use App\Enums\CheckoutPath;
use App\Models\Booking;
use App\Models\CheckoutSession;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class SubmitEngineCheckout extends Action
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{path: CheckoutPath, bookings: Collection<int, Booking>, email: string}
     */
    public function handle(CheckoutSession $session, array $data, ?string $ip): array
    {
        throw ValidationException::withMessages([
            'stay' => ['This reservation shape has been retired.'],
        ]);
    }
}
