<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Bookings\CheckInBooking;
use App\Actions\Bookings\CheckOutBooking;
use App\Actions\Bookings\CreateStayReservation;
use App\Actions\Bookings\TransitionBooking;
use App\Actions\Payments\RecordPayment;
use App\Actions\Restrictions\SetStayRestrictions;
use App\Enums\BookingStatus;
use App\Enums\ChannelOfOrigin;
use App\Enums\ConfigKind;
use App\Enums\MainChannel;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Pricing\GuestsInvalid;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\StayQuoteInput;
use App\Services\Pricing\StayQuoter;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Stays\StayDates;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Fixture stays go through the stay actions. History on create is System
 * because this seeder does not authenticate. Check-in, check-out and payment
 * actions record the actor they are given.
 */
final class SeedHotelBookings
{
    private const CHILD_AGE = 8;

    public function __construct(
        private CreateStayReservation $stays,
        private StayQuoter $quoter,
        private TransitionBooking $transitions,
        private RecordPayment $payments,
        private CheckInBooking $checkIn,
        private CheckOutBooking $checkOut,
        private SetStayRestrictions $restrictions,
    ) {}

    public function run(Property $property): void
    {
        $actor = User::query()->where('email', 'carolina@iconic.test')->first();

        if (! $actor instanceof User) {
            throw new RuntimeException('Hotel booking seed needs carolina@iconic.test.');
        }

        $this->publishRoomRates($actor);

        /** @var array{restrictions?: list<array<string, mixed>>, bookings?: list<array<string, mixed>>} $seed */
        $seed = $this->fixture();
        $this->seedRestrictions($property, $seed['restrictions'] ?? [], $actor);

        try {
            $this->seedBookings($property, $seed['bookings'] ?? [], $actor);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Config is published before room types exist, so the first rates version
     * drops room rates. Publish again once the hotel types are in the database.
     */
    private function publishRoomRates(User $actor): void
    {
        $config = app(CurrentConfig::class);

        if ($config->rates()->roomRates !== []) {
            return;
        }

        $version = $config->version(ConfigKind::Rates);

        app(ConfigPublisher::class)->publish(
            ConfigKind::Rates,
            RatesDocument::initial(),
            $version->version,
            'Sprint 19: hotel room rates after room types exist',
            $actor,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function seedRestrictions(Property $property, array $rows, User $actor): void
    {
        foreach ($rows as $row) {
            $typeIds = [];
            $code = $row['room_type'] ?? null;

            if (is_string($code) && $code !== '') {
                $type = RoomType::query()
                    ->where('property_id', $property->id)
                    ->where('code', $code)
                    ->first();

                if (! $type instanceof RoomType) {
                    throw new RuntimeException('Hotel restriction uses an unknown room type '.$code.'.');
                }

                $typeIds = [$type->id];
            }

            $this->restrictions->handle([
                'property_id' => $property->id,
                'room_type_ids' => $typeIds,
                'from' => $row['from'],
                'to' => $row['to'],
                'stop_sell' => (bool) ($row['stop_sell'] ?? false),
                'closed_to_arrival' => (bool) ($row['closed_to_arrival'] ?? false),
                'closed_to_departure' => (bool) ($row['closed_to_departure'] ?? false),
                'min_stay' => $row['min_stay'] ?? null,
                'note' => $row['note'] ?? null,
                'reason' => 'Hotel seed fixture',
            ], $actor);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function seedBookings(Property $property, array $rows, User $actor): void
    {
        $group = [];
        $rest = [];

        foreach ($rows as $row) {
            if (($row['group'] ?? null) === 'GRP-001') {
                $group[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        if ($group !== [] && ! $this->exists((string) $group[0]['reference'])) {
            $this->at((string) $group[0]['check_in']);
            $created = $this->sell($property, $group, $actor, 'GRP-001');

            foreach ($created as $booking) {
                $row = $this->rowForRoom($group, (int) $booking->room_id);
                $this->stamp($booking, (string) $row['reference']);
                $this->advance($booking, (string) $row['status'], $actor);
            }
        }

        foreach ($rest as $row) {
            $reference = (string) $row['reference'];

            if ($this->exists($reference)) {
                continue;
            }

            $this->at((string) $row['check_in']);
            $created = $this->sell($property, [$row], $actor, null);

            if ($created === []) {
                continue;
            }

            $booking = $created[0];
            $requested = (string) $row['status'] === 'REQUESTED';
            $this->stamp($booking, $reference, $requested);

            if (! $requested) {
                $this->advance($booking, (string) $row['status'], $actor);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Booking>
     */
    private function sell(Property $property, array $rows, User $actor, ?string $groupName): array
    {
        $rooms = [];
        $total = 0;
        $first = null;

        foreach ($rows as $row) {
            $type = RoomType::query()
                ->where('property_id', $property->id)
                ->where('code', $row['room_type'])
                ->first();
            $room = Room::query()
                ->where('property_id', $property->id)
                ->where('code', $row['room'])
                ->first();

            if (! $type instanceof RoomType || ! $room instanceof Room) {
                throw new RuntimeException('Hotel booking '.$row['reference'].' names an unknown room.');
            }

            $stay = StayDates::forNights((string) $row['check_in'], (int) $row['nights']);
            $ages = array_fill(0, (int) $row['children'], self::CHILD_AGE);
            $quoted = $this->quoter->quote($type, new StayQuoteInput(
                $stay,
                $type->code,
                (int) $row['adults'],
                $ages,
                (string) $row['rate_plan'],
            ));

            if ($quoted instanceof NoRate) {
                return [];
            }

            if ($quoted instanceof GuestsInvalid) {
                throw new RuntimeException('Hotel booking '.$row['reference'].' guests: '.implode(' ', $quoted->errors));
            }

            $total += $quoted->quote->total;
            $first ??= $stay;
            $rooms[] = [
                'room_type' => $type->code,
                'room_id' => $room->id,
                'adults' => (int) $row['adults'],
                'child_ages' => $ages,
                'rate_plan' => (string) $row['rate_plan'],
                'check_in' => $stay->checkIn()->toDateString(),
                'check_out' => $stay->checkOut()->toDateString(),
            ];
        }

        if ($first === null) {
            return [];
        }

        $reference = (string) $rows[0]['reference'];
        $requested = count($rows) === 1 && (string) $rows[0]['status'] === 'REQUESTED';
        $data = [
            'check_in' => $first->checkIn()->toDateString(),
            'check_out' => $first->checkOut()->toDateString(),
            'expected_total' => $total,
            'main_channel' => MainChannel::D2C,
            'channel_of_origin' => ChannelOfOrigin::Phone,
            'client' => [
                'name' => 'Hotel seed '.$reference,
                'email' => strtolower($reference).'@hotel-demo.test',
            ],
            'rooms' => $rooms,
            'override_restrictions' => true,
            'override_reason' => 'Hotel seed fixture',
        ];

        if ($groupName !== null) {
            $data['group'] = ['name' => $groupName];
        }

        if ($requested) {
            return [$this->stays->request($data, $actor)];
        }

        return $this->stays->handle($data, $actor)->bookings->values()->all();
    }

    private function advance(Booking $booking, string $status, User $actor): void
    {
        $status = match ($status) {
            'ON_BOARD' => BookingStatus::InHouse->value,
            'COMPLETED' => BookingStatus::CheckedOut->value,
            default => $status,
        };

        if ($status === BookingStatus::PendingPayment->value || $status === BookingStatus::Requested->value) {
            return;
        }

        if ($status === BookingStatus::Cancelled->value) {
            $this->transitions->handle($booking, [
                'to' => BookingStatus::Cancelled,
                'reason' => 'Hotel seed fixture status CANCELLED',
            ], $actor, system: true);

            return;
        }

        if ($status === BookingStatus::Confirmed->value) {
            $this->transitions->handle($booking, [
                'to' => BookingStatus::Confirmed,
            ], $actor, system: true);

            return;
        }

        $this->payments->handle($booking->refresh(), [
            'kind' => PaymentKind::Balance,
            'method' => PaymentMethod::Other,
            'amount' => $booking->chargesTotalFresh(),
            'note' => 'Hotel seed fixture',
        ], $actor);

        if ($status === BookingStatus::FullyPaid->value) {
            return;
        }

        $booking = $this->checkIn->handle($booking->refresh(), [], $actor);

        if ($status === BookingStatus::InHouse->value) {
            return;
        }

        if ($status !== BookingStatus::CheckedOut->value) {
            throw new RuntimeException('Hotel seed cannot reach status '.$status.'.');
        }

        $this->at($booking->stay()->checkOut()->toDateString());
        $this->checkOut->handle($booking->refresh(), [], $actor);
    }

    private function stamp(Booking $booking, string $reference, bool $request = false): void
    {
        if ($request) {
            $booking->request_reference = $reference;
        } else {
            $booking->reference = $reference;
        }

        $booking->save();
    }

    private function exists(string $reference): bool
    {
        return Booking::query()
            ->where('reference', $reference)
            ->orWhere('request_reference', $reference)
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowForRoom(array $rows, int $roomId): array
    {
        $code = Room::query()->whereKey($roomId)->value('code');

        foreach ($rows as $row) {
            if ($row['room'] === $code) {
                return $row;
            }
        }

        throw new RuntimeException('Hotel group booking landed on an unexpected room.');
    }

    private function at(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00', 'Pacific/Galapagos'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = base_path('docs/requirements/examples/hotel-seed-data.json');
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('hotel-seed-data.json is not an object.');
        }

        return $decoded;
    }
}
