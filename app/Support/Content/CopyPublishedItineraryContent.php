<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Enums\ItineraryStatus;
use App\Models\Itinerary;
use App\Models\Property;

/**
 * One-off for yacht installs. Copies the first published itinerary's
 * highlights and FAQs onto properties that do not already have them.
 * Hotel seed already has both, so those rows are left alone (09 H20).
 */
final class CopyPublishedItineraryContent
{
    public function handle(): int
    {
        $itinerary = Itinerary::query()
            ->where('status', ItineraryStatus::Published)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $itinerary instanceof Itinerary) {
            return 0;
        }

        $copied = 0;

        Property::query()->orderBy('id')->each(function (Property $property) use ($itinerary, &$copied): void {
            $dirty = false;

            if ($this->emptyList($property->highlights) && $itinerary->highlights !== []) {
                $property->highlights = $itinerary->highlights;
                $dirty = true;
            }

            if ($this->emptyList($property->faqs) && $itinerary->faqs !== []) {
                $property->faqs = $itinerary->faqs;
                $dirty = true;
            }

            if (! $dirty) {
                return;
            }

            $property->save();
            $copied++;
        });

        return $copied;
    }

    /**
     * @param  list<mixed>|null  $value
     */
    private function emptyList(?array $value): bool
    {
        return $value === null || $value === [];
    }
}
