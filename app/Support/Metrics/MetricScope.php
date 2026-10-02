<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\ChannelOfOriginGroup;

/**
 * Optional filters shared by every commercial metric. Null means the dimension is open.
 */
final readonly class MetricScope
{
    public function __construct(
        public ?int $propertyId = null,
        public ?int $itineraryId = null,
        public ?ChannelOfOriginGroup $channel = null,
        public ?int $agencyId = null,
    ) {}

    public function restrictsBookings(): bool
    {
        return $this->propertyId !== null
            || $this->itineraryId !== null
            || $this->channel !== null
            || $this->agencyId !== null;
    }

    /**
     * @return array{property: int|null, itinerary: int|null, channel: string|null, agency: int|null}
     */
    public function toArray(): array
    {
        return [
            'property' => $this->propertyId,
            'itinerary' => $this->itineraryId,
            'channel' => $this->channel?->value,
            'agency' => $this->agencyId,
        ];
    }
}
