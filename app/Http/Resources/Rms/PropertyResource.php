<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Property;
use App\Support\Content\Completeness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     slug: string|null,
     *     timezone: string|null,
     *     address_line_1: string|null,
     *     address_line_2: string|null,
     *     city: string|null,
     *     postcode: string|null,
     *     country: string|null,
     *     phone: string|null,
     *     email: string|null,
     *     description: string|null,
     *     hero_image_path: string|null,
     *     hero_alt: string|null,
     *     highlights: list<string>|null,
     *     facts: list<array{0: string, 1: string}>|null,
     *     faqs: list<array{0: string, 1: string}>|null,
     *     policies_text: string|null,
     *     meta_title: string|null,
     *     meta_description: string|null,
     *     hero_image_url: string|null,
     *     completeness: array{pct: int, missing: list<string>, blocking: list<string>},
     *     status: string,
     *     rooms: list<array{id: int, code: string, label: string, floor: string|null, sort: int, status: string, room_type: array{id: int, code: string, name: string}}>
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('rooms.roomType');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'description' => $this->description,
            'hero_image_path' => $this->hero_image_path,
            'hero_alt' => $this->hero_alt,
            'highlights' => $this->highlights,
            'facts' => $this->facts,
            'faqs' => $this->faqs,
            'policies_text' => $this->policies_text,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'hero_image_url' => $this->heroImageUrl(),
            'completeness' => Completeness::forProperty($this->resource)->toArray(),
            'status' => $this->status->value,
            'rooms' => $this->roomPayloads(),
        ];
    }

    /**
     * @return list<array{id: int, code: string, label: string, floor: string|null, sort: int, status: string, room_type: array{id: int, code: string, name: string}}>
     */
    private function roomPayloads(): array
    {
        $rooms = [];

        foreach ($this->rooms as $room) {
            $rooms[] = [
                'id' => $room->id,
                'code' => $room->code,
                'label' => $room->label,
                'floor' => $room->floor,
                'sort' => $room->sort,
                'status' => $room->status->value,
                'room_type' => [
                    'id' => $room->roomType->id,
                    'code' => $room->roomType->code,
                    'name' => $room->roomType->name,
                ],
            ];
        }

        return $rooms;
    }

    private function heroImageUrl(): ?string
    {
        if (! is_string($this->hero_image_path) || $this->hero_image_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->hero_image_path);
    }
}
