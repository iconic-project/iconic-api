<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\RoomType;
use App\Support\Content\Completeness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin RoomType
 */
class RoomTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'code' => $this->code,
            'name' => $this->name,
            'base_occupancy' => $this->base_occupancy,
            'max_occupancy' => $this->max_occupancy,
            'max_adults' => $this->max_adults,
            'max_children' => $this->max_children,
            'waitlist_enabled' => $this->waitlist_enabled,
            'sort' => $this->sort,
            'status' => $this->status->value,
            'slug' => $this->slug,
            'description' => $this->description,
            'size_sqm' => $this->size_sqm,
            'bed_setup' => $this->bed_setup,
            'amenities' => $this->amenities,
            'photos' => $this->photoPayloads(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'completeness' => Completeness::forRoomType($this->resource)->toArray(),
            'engine_visible' => Completeness::engineVisible($this->resource),
        ];
    }

    /**
     * @return list<array{path: string, alt: string|null, url: string}>
     */
    private function photoPayloads(): array
    {
        $photos = [];

        foreach ($this->photos ?? [] as $photo) {
            if ($photo['path'] === '') {
                continue;
            }

            $alt = $photo['alt'];

            $photos[] = [
                'path' => $photo['path'],
                'alt' => is_string($alt) && $alt !== '' ? $alt : null,
                'url' => Storage::disk('public')->url($photo['path']),
            ];
        }

        return $photos;
    }
}
