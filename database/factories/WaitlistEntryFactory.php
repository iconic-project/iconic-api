<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WaitlistSource;
use App\Models\Contact;
use App\Models\RoomType;
use App\Models\WaitlistEntry;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stay = StayDates::forNights('2028-03-05', 3);

        return [
            'room_type_id' => RoomType::factory(),
            'check_in' => $stay->checkIn()->toDateString(),
            'check_out' => $stay->checkOut()->toDateString(),
            'contact_id' => Contact::factory(),
            'adults' => 2,
            'children' => 0,
            'notes' => null,
            'source' => WaitlistSource::Rms,
            'notified_at' => null,
            'notified_by' => null,
            'notified_channel' => null,
            'removed_at' => null,
            'removed_by' => null,
            'removed_reason' => null,
        ];
    }
}
