<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Enums\ContactSegment;
use App\Models\Contact;
use App\Support\Crm\AttributionTouch;
use App\Support\Crm\ContactConsentSummary;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
#[SchemaName('CrmContactResource')]
class ContactResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string|null,
     *     phone: string|null,
     *     phone_e164: string|null,
     *     country: string|null,
     *     language: string,
     *     preferred_channel: string,
     *     type: string,
     *     first_touch: array{source?: string, medium?: string, campaign?: string, content?: string, term?: string, landing_path?: string, captured_at?: string}|null,
     *     last_touch: array{source?: string, medium?: string, campaign?: string, content?: string, term?: string, landing_path?: string, captured_at?: string}|null,
     *     lifetime_value: int,
     *     last_stay_check_out: string|null,
     *     next_stay_check_in: string|null,
     *     stays_count: int,
     *     nights_count: int,
     *     last_room_type: string|null,
     *     segment: ContactSegment,
     *     lifecycle: string,
     *     nps: int|null,
     *     consent: array{marketing: bool, transactional: true},
     *     main_channel: string|null,
     *     channel_of_origin: string|null,
     *     resolved_from_alias: bool,
     *     alias_id: int|null,
     *     merge_id: int|null,
     *     bookings?: list<ContactBookingResource>
     * }
     *
     * @phpstan-return array{
     *     id: int,
     *     name: string,
     *     email: string|null,
     *     phone: string|null,
     *     phone_e164: string|null,
     *     country: string|null,
     *     language: string,
     *     preferred_channel: string,
     *     type: string,
     *     first_touch: array<string, string>|null,
     *     last_touch: array<string, string>|null,
     *     lifetime_value: int,
     *     last_stay_check_out: string|null,
     *     next_stay_check_in: string|null,
     *     stays_count: int,
     *     nights_count: int,
     *     last_room_type: string|null,
     *     segment: string,
     *     lifecycle: string,
     *     nps: int|null,
     *     consent: array{marketing: bool, transactional: true},
     *     main_channel: string|null,
     *     channel_of_origin: string|null,
     *     resolved_from_alias: bool,
     *     alias_id: int|null,
     *     merge_id: int|null,
     *     bookings?: AnonymousResourceCollection
     * }
     */
    public function toArray(Request $request): array
    {
        $consent = ContactConsentSummary::for($this->resource);

        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_e164' => $this->phone_e164,
            'country' => $this->country,
            'language' => $this->language,
            'preferred_channel' => $this->preferred_channel->value,
            'type' => $this->type->value,
            'first_touch' => AttributionTouch::from($this->first_touch),
            'last_touch' => AttributionTouch::from($this->last_touch),
            'lifetime_value' => (int) $this->getAttribute('lifetime_value'),
            'last_stay_check_out' => $this->calendarValue('last_stay_check_out'),
            'next_stay_check_in' => $this->calendarValue('next_stay_check_in'),
            'stays_count' => (int) $this->getAttribute('stays_count'),
            'nights_count' => (int) $this->getAttribute('nights_count'),
            'last_room_type' => $this->nullableString($this->getAttribute('last_room_type')),
            'segment' => $this->contactSegment(),
            'lifecycle' => (string) $this->getAttribute('lifecycle'),
            'nps' => $this->getAttribute('nps') === null ? null : (int) $this->getAttribute('nps'),
            'consent' => $consent,
            'main_channel' => $this->nullableString($this->getAttribute('first_main_channel')),
            'channel_of_origin' => $this->nullableString($this->getAttribute('first_channel_of_origin')),
            'resolved_from_alias' => $this->resource->resolvedFromAliasId !== null,
            'alias_id' => $this->resource->resolvedFromAliasId,
            'merge_id' => $this->resource->resolvedMergeId,
        ];

        if ($this->relationLoaded('bookings')) {
            $payload['bookings'] = ContactBookingResource::collection($this->bookings);
        }

        return $payload;
    }

    /**
     * The band is selected in SQL, not cast. Booking embeds omit that column;
     * the string stays empty rather than becoming NEW.
     *
     * @scramble-return ContactSegment
     */
    private function contactSegment(): string
    {
        return (string) $this->getAttribute('segment');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function calendarValue(string $attribute): ?string
    {
        $value = $this->getAttribute($attribute);

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }
}
