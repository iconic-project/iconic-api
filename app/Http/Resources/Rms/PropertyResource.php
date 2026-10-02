<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
     *     status: string,
     *     cabins: list<array{id: int, code: string, label: string, category: string, sort: int}>
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('cabins');

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
            'status' => $this->status->value,
            'cabins' => $this->cabinPayloads(),
        ];
    }

    /**
     * @return list<array{id: int, code: string, label: string, category: string, sort: int}>
     */
    private function cabinPayloads(): array
    {
        $cabins = [];

        foreach ($this->cabins as $cabin) {
            $cabins[] = [
                'id' => $cabin->id,
                'code' => $cabin->code,
                'label' => $cabin->label,
                'category' => $cabin->category->value,
                'sort' => $cabin->sort,
            ];
        }

        return $cabins;
    }
}
