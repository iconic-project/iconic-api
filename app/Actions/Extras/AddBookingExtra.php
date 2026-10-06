<?php

declare(strict_types=1);

namespace App\Actions\Extras;

use App\Actions\Action;
use App\Events\BookingChargesChanged;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\BookingCharges;
use App\Support\Bookings\BookingMutationLock;
use App\Support\History\History;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

final class AddBookingExtra extends Action
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): BookingExtra
    {
        return $this->transaction(function () use ($booking, $data, $actor): BookingExtra {
            $booking = BookingMutationLock::acquire($booking);
            BookingCharges::assertWritable($booking);

            $code = (string) $data['code'];
            $item = $this->config->extras()->find($code);

            if ($item === null || ! $item->active) {
                throw ValidationException::withMessages([
                    'code' => ['This extra is not available.'],
                ]);
            }

            $qty = (int) $data['qty'];
            $rate = array_key_exists('rate_usd', $data) && $data['rate_usd'] !== null
                ? (int) $data['rate_usd']
                : $item->priceUsd;

            if ($rate === null) {
                throw ValidationException::withMessages([
                    'rate_usd' => ['A rate is required for an on-request extra.'],
                ]);
            }

            $note = isset($data['note']) && is_string($data['note']) && trim($data['note']) !== ''
                ? trim($data['note'])
                : null;

            $extra = BookingExtra::query()->create([
                'booking_id' => $booking->id,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'qty' => $qty,
                'rate_usd' => $rate,
                'note' => $note,
            ]);

            $reason = 'Extra added — '.$extra->name.' × '.$extra->qty;

            History::record($booking, 'extra.added', after: [
                'extra_id' => $extra->id,
                'code' => $extra->code,
                'qty' => $extra->qty,
                'rate_usd' => $extra->rate_usd,
                'what' => $reason.' @ '.Money::format($extra->rate_usd),
            ], actor: $actor);

            BookingChargesChanged::dispatch($booking, $reason);

            return $extra;
        });
    }
}
