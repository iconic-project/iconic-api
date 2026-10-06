<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\BookingSegment;
use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Support\BusinessTime;
use App\Support\Offers\OfferGuardrails;
use App\Support\Offers\OfferPresentation;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference
 * @property string $code
 * @property string $name
 * @property OfferType $type
 * @property int|null $value
 * @property string|null $value_text
 * @property OfferChannel $channel
 * @property string|null $partner
 * @property CarbonImmutable|null $booking_from
 * @property CarbonImmutable|null $booking_to
 * @property CarbonImmutable|null $stay_from
 * @property CarbonImmutable|null $stay_to
 * @property int|null $min_nights
 * @property list<string>|null $applies_to_room_types
 * @property list<string>|null $applies_to_rate_plans
 * @property bool $combinable
 * @property bool $is_promo_code
 * @property string|null $badge
 * @property bool $show_on_card
 * @property bool $show_on_calendar
 * @property string|null $price_line
 * @property string|null $terms
 * @property OfferStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $approval_reason
 * @property bool $needs_reapproval
 * @property Carbon|null $first_live_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $approvedBy
 *
 * @method static Builder<static> query()
 */
#[Fillable([
    'reference',
    'code',
    'name',
    'type',
    'value',
    'value_text',
    'channel',
    'partner',
    'booking_from',
    'booking_to',
    'stay_from',
    'stay_to',
    'min_nights',
    'applies_to_room_types',
    'applies_to_rate_plans',
    'combinable',
    'is_promo_code',
    'badge',
    'show_on_card',
    'show_on_calendar',
    'price_line',
    'terms',
    'status',
    'approved_by',
    'approved_at',
    'approval_reason',
    'needs_reapproval',
    'first_live_at',
])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc;

    /**
     * @return list<string>
     */
    public static function materialFields(): array
    {
        return [
            'type',
            'value',
            'value_text',
            'channel',
            'partner',
            'booking_from',
            'booking_to',
            'stay_from',
            'stay_to',
            'min_nights',
            'applies_to_room_types',
            'applies_to_rate_plans',
            'combinable',
            'is_promo_code',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OfferType::class,
            'value' => 'integer',
            'channel' => OfferChannel::class,
            'booking_from' => CalendarDate::class,
            'booking_to' => CalendarDate::class,
            'stay_from' => CalendarDate::class,
            'stay_to' => CalendarDate::class,
            'min_nights' => 'integer',
            'applies_to_room_types' => 'array',
            'applies_to_rate_plans' => 'array',
            'combinable' => 'boolean',
            'is_promo_code' => 'boolean',
            'show_on_card' => 'boolean',
            'show_on_calendar' => 'boolean',
            'status' => OfferStatus::class,
            'approved_at' => 'datetime',
            'needs_reapproval' => 'boolean',
            'first_live_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Offer $offer): void {
            $normalized = OfferGuardrails::normalize([
                'code' => $offer->code,
                'channel' => $offer->channel,
                'is_promo_code' => $offer->is_promo_code,
                'show_on_card' => $offer->show_on_card,
                'show_on_calendar' => $offer->show_on_calendar,
            ]);

            $offer->code = (string) $normalized['code'];
            $offer->show_on_card = (bool) $normalized['show_on_card'];
            $offer->show_on_calendar = (bool) $normalized['show_on_calendar'];

            OfferGuardrails::validate($offer->attributesForGuardrails(), $offer->exists ? $offer : null);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForGuardrails(): array
    {
        return [
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'value_text' => $this->value_text,
            'channel' => $this->channel,
            'booking_from' => $this->booking_from,
            'booking_to' => $this->booking_to,
            'stay_from' => $this->stay_from,
            'stay_to' => $this->stay_to,
            'min_nights' => $this->min_nights,
            'applies_to_room_types' => $this->applies_to_room_types,
            'applies_to_rate_plans' => $this->applies_to_rate_plans,
            'is_promo_code' => $this->is_promo_code,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    public function historyLabel(): string
    {
        return $this->code;
    }

    public function windowEnd(): ?CarbonImmutable
    {
        $ends = array_values(array_filter([
            $this->stay_to,
            $this->booking_to,
        ]));

        if ($ends === []) {
            return null;
        }

        usort($ends, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $ends[array_key_last($ends)];
    }

    public function isDerivedExpired(?string $today = null): bool
    {
        if (! in_array($this->status, [OfferStatus::Live, OfferStatus::Paused], true)) {
            return false;
        }

        $end = $this->windowEnd();

        if (! $end instanceof CarbonImmutable) {
            return false;
        }

        $today ??= BusinessTime::now()->toDateString();

        return $end->toDateString() < $today;
    }

    public function derivedStatus(?string $today = null): string
    {
        if ($this->isDerivedExpired($today)) {
            return OfferStatus::DerivedExpired;
        }

        return $this->status->value;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function span(): array
    {
        return [
            $this->stay_from ?? $this->booking_from,
            $this->stay_to ?? $this->booking_to,
        ];
    }

    public function spanOverlaps(?string $from, ?string $to): bool
    {
        [$start, $end] = $this->span();

        if ($start === null && $end === null) {
            return true;
        }

        $spanStart = ($start ?? $end)->toDateString();
        $spanEnd = ($end ?? $start)->toDateString();

        if ($from !== null && $from !== '' && $spanEnd < $from) {
            return false;
        }

        if ($to !== null && $to !== '' && $spanStart > $to) {
            return false;
        }

        return true;
    }

    public function benefitLabel(): string
    {
        return OfferPresentation::benefit($this);
    }

    public function scopeLabel(): string
    {
        return OfferPresentation::scope($this);
    }

    public function bookingWindowLabel(): string
    {
        return OfferPresentation::window($this->booking_from, $this->booking_to);
    }

    public function travelWindowLabel(): string
    {
        return OfferPresentation::window($this->stay_from, $this->stay_to);
    }

    public function stayWindowLabel(): string
    {
        return OfferPresentation::window($this->stay_from, $this->stay_to);
    }

    /**
     * Nights of the stay that fall in [stay_from, stay_to]. Null bounds are open.
     *
     * @return list<string>
     */
    public function eligibleNights(StayDates $stay): array
    {
        $nights = [];

        foreach ($stay->eachNight() as $night) {
            $date = $night->toDateString();

            if ($this->stay_from instanceof CarbonInterface && $this->stay_from->toDateString() > $date) {
                continue;
            }

            if ($this->stay_to instanceof CarbonInterface && $this->stay_to->toDateString() < $date) {
                continue;
            }

            $nights[] = $date;
        }

        return $nights;
    }

    public function enginePlacement(): string
    {
        return OfferPresentation::enginePlacement($this);
    }

    /**
     * Live offers whose stay window overlaps the stay. A null room type or rate plan skips that filter.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForStay(
        Builder $query,
        StayDates $stay,
        ?string $roomType,
        ?string $ratePlan,
        BookingSegment $channel,
        string $bookingDate,
        ?string $code = null,
    ): Builder {
        if ($channel === BookingSegment::Charter) {
            return $query->whereRaw('0 = 1');
        }

        $channels = $channel === BookingSegment::B2B
            ? [OfferChannel::B2B, OfferChannel::All]
            : [OfferChannel::D2C, OfferChannel::All];

        $first = $stay->checkIn()->toDateString();
        $last = $stay->lastNight()->toDateString();
        $nights = $stay->nights();

        $query->where('status', OfferStatus::Live)
            ->whereIn('channel', array_map(fn (OfferChannel $offerChannel): string => $offerChannel->value, $channels))
            ->where(function (Builder $inner) use ($nights): void {
                $inner->whereNull('min_nights')->orWhere('min_nights', '<=', $nights);
            })
            ->where(function (Builder $inner) use ($bookingDate): void {
                $inner->whereNull('booking_from')->orWhere('booking_from', '<=', $bookingDate);
            })
            ->where(function (Builder $inner) use ($bookingDate): void {
                $inner->whereNull('booking_to')->orWhere('booking_to', '>=', $bookingDate);
            })
            ->where(function (Builder $inner) use ($last): void {
                $inner->whereNull('stay_from')->orWhere('stay_from', '<=', $last);
            })
            ->where(function (Builder $inner) use ($first): void {
                $inner->whereNull('stay_to')->orWhere('stay_to', '>=', $first);
            });

        if (is_string($roomType) && $roomType !== '') {
            $query->where(function (Builder $inner) use ($roomType): void {
                $inner->whereNull('applies_to_room_types')
                    ->orWhereJsonContains('applies_to_room_types', $roomType);
            });
        }

        if (is_string($ratePlan) && $ratePlan !== '') {
            $query->where(function (Builder $inner) use ($ratePlan): void {
                $inner->whereNull('applies_to_rate_plans')
                    ->orWhereJsonContains('applies_to_rate_plans', $ratePlan);
            });
        }

        if ($code === null || trim($code) === '') {
            $query->where('is_promo_code', false);
        } else {
            $normalized = strtoupper(trim($code));
            $query->where(function (Builder $inner) use ($normalized): void {
                $inner->where('is_promo_code', false)
                    ->orWhere(function (Builder $promo) use ($normalized): void {
                        $promo->where('is_promo_code', true)
                            ->whereRaw('UPPER(code) = ?', [$normalized]);
                    });
            });
        }

        return $query;
    }
}
