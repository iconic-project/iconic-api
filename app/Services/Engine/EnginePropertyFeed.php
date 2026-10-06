<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Enums\PropertyStatus;
use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\SoldOn;
use App\Support\Content\Completeness;
use App\Support\Iso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public property document. Room numbers and claim holders are not included.
 */
final class EnginePropertyFeed
{
    public function __construct(
        private readonly SeasonNightly $prices,
        private readonly CurrentConfig $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $version = EngineFeedVersion::current();

        /** @var array<string, mixed> $payload */
        $payload = Cache::remember(
            EngineFeedVersion::propertyKey($version),
            15,
            fn (): array => $this->assemble(),
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function etag(array $payload): string
    {
        $copy = $payload;
        unset($copy['generated_at']);

        return hash('sha256', (string) json_encode($copy));
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(): array
    {
        $property = $this->property();
        $settings = $this->config->engineSettings();
        $stay = $this->config->businessRules()->stay;
        $from = CarbonImmutable::parse(SoldOn::today());
        $to = $from->addMonths($settings->calendar->horizonMonths);
        $types = [];

        foreach ($property->roomTypes as $type) {
            if (! Completeness::engineVisible($type)) {
                continue;
            }

            $adults = max(1, $type->base_occupancy);
            $types[] = $this->roomType($type, $this->prices->minimum($type, $from, $to, $adults, []));
        }

        $plans = [];

        foreach ($this->prices->plans() as $plan) {
            $plans[] = $plan->toArray();
        }

        return [
            'generated_at' => (string) Iso::utc(now()),
            'property' => $this->propertyPayload($property),
            'room_types' => $types,
            'default_rate_plan' => $this->prices->defaultPlan(),
            'rate_plans' => $plans,
            'settings' => [
                'copy' => $settings->copy->toArray(),
                'guests' => $settings->guests->toArray(),
                'locale' => $settings->locale->toArray(),
                'stay' => [
                    'min_nights' => $stay->minNights,
                    'max_nights' => $stay->maxNights,
                    'max_rooms_per_booking' => $stay->maxRoomsPerBooking,
                ],
                'availability' => $settings->availability->toArray(),
            ],
        ];
    }

    public function property(): Property
    {
        $properties = Property::query()
            ->where('status', PropertyStatus::Active)
            ->with(['roomTypes' => fn ($query) => $query->where('status', RoomTypeStatus::Active)])
            ->orderBy('code')
            ->get();

        foreach ($properties as $property) {
            foreach ($property->roomTypes as $type) {
                if (Completeness::engineVisible($type)) {
                    return $property;
                }
            }
        }

        throw new NotFoundHttpException('No published property.');
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyPayload(Property $property): array
    {
        return [
            'code' => $property->code,
            'name' => $property->name,
            'slug' => $property->slug,
            'address_line_1' => $property->address_line_1,
            'address_line_2' => $property->address_line_2,
            'city' => $property->city,
            'postcode' => $property->postcode,
            'country' => $property->country,
            'phone' => $property->phone,
            'email' => $property->email,
            'description' => $property->description,
            'hero_alt' => $property->hero_alt,
            'hero_image_url' => $this->url($property->hero_image_path),
            'highlights' => $property->highlights,
            'facts' => $property->facts,
            'faqs' => $property->faqs,
            'policies_text' => $property->policies_text,
            'meta_title' => $property->meta_title,
            'meta_description' => $property->meta_description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roomType(RoomType $type, ?int $fromPrice): array
    {
        return [
            'code' => $type->code,
            'name' => $type->name,
            'slug' => $type->slug,
            'description' => $type->description,
            'size_sqm' => $type->size_sqm,
            'bed_setup' => $type->bed_setup,
            'amenities' => $type->amenities,
            'photos' => $this->photos($type),
            'base_occupancy' => $type->base_occupancy,
            'max_occupancy' => $type->max_occupancy,
            'max_adults' => $type->max_adults,
            'max_children' => $type->max_children,
            'from_price' => $fromPrice,
            'meta_title' => $type->meta_title,
            'meta_description' => $type->meta_description,
        ];
    }

    /**
     * @return list<array{alt: string|null, url: string}>
     */
    private function photos(RoomType $type): array
    {
        $photos = [];

        foreach ($type->photos ?? [] as $photo) {
            if ($photo['path'] === '') {
                continue;
            }

            $alt = $photo['alt'];
            $photos[] = [
                'alt' => is_string($alt) && $alt !== '' ? $alt : null,
                'url' => (string) $this->url($photo['path']),
            ];
        }

        return $photos;
    }

    private function url(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
