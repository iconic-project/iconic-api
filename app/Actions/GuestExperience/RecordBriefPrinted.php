<?php

declare(strict_types=1);

namespace App\Actions\GuestExperience;

use App\Actions\Action;
use App\Models\Property;
use App\Models\User;
use App\Support\GuestExperience\ArrivalGuestList;
use App\Support\History\History;

final class RecordBriefPrinted extends Action
{
    public function __construct(private readonly ArrivalGuestList $experience) {}

    public function handle(string $date, User $actor, string $format): void
    {
        $this->transaction(function () use ($date, $actor, $format): void {
            $bookings = $this->experience->arrivals($date, $date);
            $groups = $bookings->groupBy('property_id');

            if ($groups->isEmpty()) {
                $property = Property::query()->orderBy('id')->first();

                if ($property instanceof Property) {
                    $this->entry($property, $date, $format, 0, $actor);
                }

                return;
            }

            foreach ($groups as $propertyId => $group) {
                $property = Property::query()->find($propertyId);

                if (! $property instanceof Property) {
                    continue;
                }

                $this->entry($property, $date, $format, $group->count(), $actor);
            }
        });
    }

    private function entry(Property $property, string $date, string $format, int $bookings, User $actor): void
    {
        History::record($property, 'brief.printed', after: [
            'date' => $date,
            'format' => $format,
            'booking_count' => $bookings,
        ], actor: $actor);
    }
}
