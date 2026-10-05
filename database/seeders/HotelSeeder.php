<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PropertyStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

final class HotelSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $seed = $this->fixture();
        $propertyRow = $seed['property'];
        $property = Property::query()->updateOrCreate(
            ['code' => $propertyRow['code']],
            [
                'name' => $propertyRow['name'],
                'slug' => $propertyRow['slug'],
                'timezone' => $propertyRow['timezone'],
                'address_line_1' => $propertyRow['address_line_1'],
                'address_line_2' => $propertyRow['address_line_2'],
                'city' => $propertyRow['city'],
                'postcode' => $propertyRow['postcode'],
                'country' => $propertyRow['country'],
                'phone' => $propertyRow['phone'],
                'email' => $propertyRow['email'],
                'description' => $propertyRow['description'],
                'hero_image_path' => $propertyRow['hero_image_path'],
                'hero_alt' => $propertyRow['hero_alt'],
                'highlights' => $propertyRow['highlights'],
                'facts' => $propertyRow['facts'],
                'faqs' => $propertyRow['faqs'],
                'policies_text' => $propertyRow['policies_text'],
                'meta_title' => $propertyRow['meta_title'],
                'meta_description' => $propertyRow['meta_description'],
                'status' => PropertyStatus::from($propertyRow['status']),
            ],
        );

        $types = [];

        foreach ($seed['room_types'] as $row) {
            $type = RoomType::query()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'code' => $row['code'],
                ],
                [
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'base_occupancy' => $row['base_occupancy'],
                    'max_occupancy' => $row['max_occupancy'],
                    'max_adults' => $row['max_adults'],
                    'max_children' => $row['max_children'],
                    'waitlist_enabled' => true,
                    'sort' => $row['sort'],
                    'status' => RoomTypeStatus::from($row['status']),
                    'description' => $row['description'],
                    'size_sqm' => $row['size_sqm'],
                    'bed_setup' => $row['bed_setup'],
                    'amenities' => $row['amenities'],
                ],
            );
            $types[$type->code] = $type;
        }

        foreach ($seed['rooms'] as $row) {
            $type = $types[$row['room_type']] ?? null;

            if (! $type instanceof RoomType) {
                throw new RuntimeException('Hotel fixture room '.$row['code'].' uses an unknown room type.');
            }

            Room::query()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'code' => $row['code'],
                ],
                [
                    'room_type_id' => $type->id,
                    'label' => $row['label'],
                    'floor' => $row['floor'],
                    'sort' => $row['sort'],
                    'status' => RoomStatus::Active,
                ],
            );
        }

        app(SeedHotelBookings::class)->run($property);
    }

    /**
     * @return array{
     *     property: array<string, mixed>,
     *     room_types: list<array<string, mixed>>,
     *     rooms: list<array<string, mixed>>
     * }
     */
    private function fixture(): array
    {
        $path = base_path('docs/requirements/examples/hotel-seed-data.json');

        try {
            /** @var array{property: array<string, mixed>, room_types: list<array<string, mixed>>, rooms: list<array<string, mixed>>} $seed */
            $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('hotel-seed-data.json is not valid JSON.', 0, $exception);
        }

        return $seed;
    }
}
