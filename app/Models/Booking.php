<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\CalendarDate;
use App\Enums\BookingSegment;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChannelOfOrigin;
use App\Enums\MainChannel;
use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\PngCategory;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\SerializesDatesAsUtc;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Payments\Ledger;
use App\Support\Payments\PaymentsKpis;
use App\Support\Payments\WireWindow;
use App\Support\Rounding;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string|null $reference
 * @property string|null $request_reference
 * @property BookingType $type
 * @property int $departure_id
 * @property int|null $room_id
 * @property int $contact_id
 * @property int|null $group_id
 * @property int $owner_id
 * @property int|null $agency_id
 * @property int|null $commission_pct
 * @property bool $commission_approved
 * @property int|null $commission_approved_by
 * @property Carbon|null $commission_approved_at
 * @property string|null $commission_reason
 * @property BookingStatus $status
 * @property MainChannel $main_channel
 * @property ChannelOfOrigin $channel_of_origin
 * @property array<string, mixed>|null $utm_first
 * @property array<string, mixed>|null $utm_last
 * @property int $adults
 * @property int $children
 * @property bool $back_to_back
 * @property int $rates_version_id
 * @property list<array{code: string, label: string, amount: int}> $price_lines
 * @property int $total
 * @property int $deposit_pct
 * @property CarbonImmutable|null $deposit_due_on
 * @property int $balance_days
 * @property string|null $promo_code
 * @property bool $online_deposit
 * @property CarbonImmutable $sold_on
 * @property int|null $checkout_session_id
 * @property CarbonImmutable|null $balance_due_date_override
 * @property string|null $internal_notes
 * @property string|null $billing_name
 * @property string|null $billing_address
 * @property string|null $billing_email
 * @property string|null $billing_phone
 * @property bool $png_collected
 * @property bool $tct_collected
 * @property int|null $tct_rate_usd
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Departure $departure
 * @property-read Room|null $cabin
 * @property-read Contact $contact
 * @property-read Group|null $group
 * @property-read User $owner
 * @property-read Agency|null $agency
 * @property-read CommissionPayout|null $commissionPayout
 * @property-read User|null $commissionApprovedBy
 * @property-read RateVersion $ratesVersion
 * @property-read CheckoutSession|null $checkoutSession
 * @property-read Collection<int, RoomNightClaim> $claims
 * @property-read Collection<int, RoomNightClaim> $activeClaims
 * @property-read Collection<int, Guest> $guests
 * @property-read Collection<int, BookingExtra> $extras
 * @property-read Collection<int, Consent> $consents
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, Delivery> $deliveries
 * @property-read int|null $guests_count
 * @property-read int|null $guests_complete_count
 * @property-read Collection<int, PaymentLink> $paymentLinks
 * @property-read Collection<int, BookingAccessToken> $accessTokens
 * @property-read BookingRequest|null $bookingRequest
 * @property-read RefundRequest|null $refundRequest
 * @property-read int|null $payments_paid_sum
 * @property-read int|null $payments_pledged_sum
 * @property-read int $payments_count
 * @property-read string|null $awaiting_wire_created_at
 */
#[Fillable([
    'reference',
    'request_reference',
    'type',
    'departure_id',
    'room_id',
    'contact_id',
    'group_id',
    'owner_id',
    'agency_id',
    'commission_pct',
    'commission_approved',
    'commission_approved_by',
    'commission_approved_at',
    'commission_reason',
    'status',
    'main_channel',
    'channel_of_origin',
    'utm_first',
    'utm_last',
    'adults',
    'children',
    'back_to_back',
    'rates_version_id',
    'price_lines',
    'total',
    'deposit_pct',
    'deposit_due_on',
    'balance_days',
    'promo_code',
    'online_deposit',
    'sold_on',
    'checkout_session_id',
    'balance_due_date_override',
    'internal_notes',
    'billing_name',
    'billing_address',
    'billing_email',
    'billing_phone',
    'png_collected',
    'tct_collected',
    'tct_rate_usd',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasAuditColumns, HasFactory, SerializesDatesAsUtc, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BookingType::class,
            'status' => BookingStatus::class,
            'main_channel' => MainChannel::class,
            'channel_of_origin' => ChannelOfOrigin::class,
            'utm_first' => 'array',
            'utm_last' => 'array',
            'commission_pct' => 'integer',
            'commission_approved' => 'boolean',
            'commission_approved_at' => 'datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'back_to_back' => 'boolean',
            'price_lines' => 'array',
            'total' => 'integer',
            'deposit_pct' => 'integer',
            'deposit_due_on' => CalendarDate::class,
            'balance_days' => 'integer',
            'online_deposit' => 'boolean',
            'sold_on' => CalendarDate::class,
            'balance_due_date_override' => CalendarDate::class,
            'png_collected' => 'boolean',
            'tct_collected' => 'boolean',
            'tct_rate_usd' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Departure, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function cabin(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasOne<CommissionPayout, $this>
     */
    public function commissionPayout(): HasOne
    {
        return $this->hasOne(CommissionPayout::class);
    }

    public function hasCommissionPayout(): bool
    {
        if ($this->relationLoaded('commissionPayout')) {
            return $this->commissionPayout !== null;
        }

        return $this->commissionPayout()->exists();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function commissionApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commission_approved_by');
    }

    /**
     * @return BelongsTo<RateVersion, $this>
     */
    public function ratesVersion(): BelongsTo
    {
        return $this->belongsTo(RateVersion::class, 'rates_version_id');
    }

    /**
     * @return BelongsTo<CheckoutSession, $this>
     */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /**
     * @return HasOne<BookingRequest, $this>
     */
    public function bookingRequest(): HasOne
    {
        return $this->hasOne(BookingRequest::class);
    }

    /**
     * @return HasOne<RefundRequest, $this>
     */
    public function refundRequest(): HasOne
    {
        return $this->hasOne(RefundRequest::class);
    }

    /**
     * @return HasMany<Guest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class)->orderBy('position');
    }

    /**
     * @return HasMany<BookingExtra, $this>
     */
    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /**
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * @return HasMany<PaymentLink, $this>
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<BookingAccessToken, $this>
     */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(BookingAccessToken::class);
    }

    /**
     * @return MorphMany<RoomNightClaim, $this>
     */
    public function claims(): MorphMany
    {
        return $this->morphMany(RoomNightClaim::class, 'holder');
    }

    /**
     * @return MorphMany<RoomNightClaim, $this>
     */
    public function activeClaims(): MorphMany
    {
        return $this->claims()->whereNull('released_at');
    }

    /**
     * @return MorphMany<ChangeHistory, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(ChangeHistory::class, 'subject');
    }

    public function displayReference(): ?string
    {
        return $this->reference ?? $this->request_reference;
    }

    public function partyLabel(): string
    {
        $label = $this->adults.' AD';

        if ($this->children > 0) {
            $label .= ' + '.$this->children.' CH';
        }

        return $label;
    }

    public function segment(): BookingSegment
    {
        if ($this->type !== BookingType::Cabin) {
            return BookingSegment::Charter;
        }

        return $this->main_channel->segment();
    }

    public function extrasTotal(): int
    {
        if (array_key_exists('extras_amount_sum', $this->getAttributes())) {
            return (int) $this->getAttribute('extras_amount_sum');
        }

        if ($this->relationLoaded('extras')) {
            return (int) $this->extras->sum(fn (BookingExtra $extra): int => $extra->amount());
        }

        return $this->extrasTotalFresh();
    }

    public function extrasTotalFresh(): int
    {
        return (int) $this->extras()->sum(DB::raw('qty * rate_usd'));
    }

    public function feesCollectedTotal(): int
    {
        if (array_key_exists('fees_collected_sum', $this->getAttributes())) {
            return (int) $this->getAttribute('fees_collected_sum');
        }

        return $this->pngCollectedTotal() + $this->tctCollectedTotal();
    }

    public function feesCollectedFresh(): int
    {
        return $this->pngCollectedFresh() + $this->tctCollectedFresh();
    }

    public function chargesTotal(): int
    {
        return $this->total + $this->extrasTotal() + $this->feesCollectedTotal();
    }

    public function chargesTotalFresh(): int
    {
        return $this->total + $this->extrasTotalFresh() + $this->feesCollectedFresh();
    }

    public function balance(): int
    {
        return $this->chargesTotal() - Ledger::paid($this);
    }

    public function balanceFresh(): int
    {
        return $this->chargesTotalFresh() - Ledger::paidFresh($this);
    }

    public function cruiseOutstanding(): int
    {
        return max(0, $this->total - Ledger::paid($this));
    }

    public function cruiseOutstandingFresh(): int
    {
        return max(0, $this->total - Ledger::paidFresh($this));
    }

    public function pngPendingCount(): int
    {
        if (array_key_exists('png_pending_count_agg', $this->getAttributes())) {
            return (int) $this->getAttribute('png_pending_count_agg');
        }

        $this->loadMissing('guests');

        return $this->guests
            ->filter(fn (Guest $guest): bool => $guest->png_category === PngCategory::Pending)
            ->count();
    }

    public function extrasDueAt(): CarbonImmutable
    {
        $this->loadMissing('departure');

        $hours = app(CurrentConfig::class)->businessRules()->payments->extrasDueHours;

        return BusinessTime::calendarDay($this->departure->date->toDateString())->subHours($hours);
    }

    private function pngCollectedTotal(): int
    {
        if (! $this->png_collected) {
            return 0;
        }

        $this->loadMissing('guests');

        return (int) $this->guests->sum(fn (Guest $guest): int => $guest->png_fee ?? 0);
    }

    private function tctCollectedTotal(): int
    {
        if (! $this->tct_collected) {
            return 0;
        }

        $this->loadMissing('guests');

        return (int) ($this->tct_rate_usd ?? 0) * $this->guests->count();
    }

    private function pngCollectedFresh(): int
    {
        if (! $this->png_collected) {
            return 0;
        }

        return (int) $this->guests()->whereNotNull('png_fee')->sum('png_fee');
    }

    private function tctCollectedFresh(): int
    {
        if (! $this->tct_collected) {
            return 0;
        }

        return (int) ($this->tct_rate_usd ?? 0) * $this->guests()->count();
    }

    public function balanceDueDate(): CarbonImmutable
    {
        if ($this->balance_due_date_override instanceof CarbonImmutable) {
            return $this->balance_due_date_override;
        }

        $this->loadMissing('departure');

        return $this->departure->date->subDays($this->balance_days);
    }

    public function isOverdue(): bool
    {
        if (! in_array($this->status, [BookingStatus::Confirmed, BookingStatus::OnHoldAgency], true)) {
            return false;
        }

        if ($this->cruiseOutstanding() <= 0) {
            return false;
        }

        return BusinessTime::now()->toDateString() > $this->balanceDueDate()->toDateString();
    }

    public function overdueDays(): ?int
    {
        if (! $this->isOverdue()) {
            return null;
        }

        return (int) $this->balanceDueDate()->diffInDays(BusinessTime::now()->toDateString());
    }

    public function overdueSince(): ?CarbonImmutable
    {
        if (! $this->isOverdue()) {
            return null;
        }

        return $this->balanceDueDate();
    }

    public function wireWindowEndsAt(): ?CarbonImmutable
    {
        $started = $this->awaitingWireStartedAt();

        if ($started === null) {
            return null;
        }

        return WireWindow::endsAt($started);
    }

    public function dueDateChangedAt(): CarbonImmutable
    {
        if ($this->balance_due_date_override instanceof CarbonImmutable) {
            $extended = $this->history()
                ->where('event', 'booking.overdue_extended')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($extended !== null) {
                return CarbonImmutable::instance($extended->created_at);
            }

            return CarbonImmutable::instance($this->updated_at);
        }

        return CarbonImmutable::instance($this->created_at);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function paidSql(): array
    {
        $paid = PaymentStatus::paidValues();
        $placeholders = implode(', ', array_fill(0, count($paid), '?'));

        return [
            'COALESCE((
                SELECT SUM(payments.amount) FROM payments
                WHERE payments.booking_id = bookings.id
                  AND payments.status IN ('.$placeholders.')
            ), 0)',
            $paid,
        ];
    }

    public static function extrasTotalSql(): string
    {
        return 'COALESCE((
            SELECT SUM(booking_extras.qty * booking_extras.rate_usd)
            FROM booking_extras
            WHERE booking_extras.booking_id = bookings.id
        ), 0)';
    }

    public static function feesCollectedSql(): string
    {
        return '(CASE WHEN bookings.png_collected = 1 THEN COALESCE((
            SELECT SUM(guests.png_fee) FROM guests
            WHERE guests.booking_id = bookings.id
              AND guests.png_fee IS NOT NULL
        ), 0) ELSE 0 END)
        + (CASE WHEN bookings.tct_collected = 1 THEN
            COALESCE(bookings.tct_rate_usd, 0) * COALESCE((
                SELECT COUNT(*) FROM guests WHERE guests.booking_id = bookings.id
            ), 0)
        ELSE 0 END)';
    }

    public static function chargesTotalSql(): string
    {
        return 'bookings.total + ('.self::extrasTotalSql().') + ('.self::feesCollectedSql().')';
    }

    public static function pngPendingCountSql(): string
    {
        return 'COALESCE((
            SELECT COUNT(*) FROM guests
            WHERE guests.booking_id = bookings.id
              AND guests.png_category = \''.PngCategory::Pending->value.'\'
        ), 0)';
    }

    /**
     * SQL fragment: charges total minus payments that count as paid.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function balanceSql(): array
    {
        [$paidSql, $paid] = self::paidSql();

        return [
            '('.self::chargesTotalSql().') - ('.$paidSql.')',
            $paid,
        ];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function cruiseOutstandingSql(): array
    {
        [$paidSql, $paid] = self::paidSql();

        return [
            'GREATEST(0, bookings.total - ('.$paidSql.'))',
            $paid,
        ];
    }

    /**
     * Owing bookings for the Payments & Revenue pending table.
     * Same set as PaymentsKpis::owingStatuses() with a positive balance.
     *
     * @param  Builder<self>  $query
     */
    public function scopePendingPayment(Builder $query): void
    {
        [$balanceSql, $paid] = self::balanceSql();

        $query
            ->whereIn('bookings.status', PaymentsKpis::owingStatuses())
            ->whereRaw('('.$balanceSql.') > 0', $paid);
    }

    /**
     * Galápagos due date: the override, or the departure date minus balance days.
     */
    public static function dueDateSql(): string
    {
        return 'COALESCE(bookings.balance_due_date_override, DATE_SUB((
            SELECT departures.date FROM departures WHERE departures.id = bookings.departure_id
        ), INTERVAL bookings.balance_days DAY))';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $today = BusinessTime::now()->toDateString();
        [$cruiseSql, $paid] = self::cruiseOutstandingSql();

        $query
            ->whereIn('bookings.status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHoldAgency->value,
            ])
            ->whereRaw('('.$cruiseSql.') > 0', $paid)
            ->whereRaw('? > '.self::dueDateSql(), [$today]);
    }

    public function depositAmount(): int
    {
        return Rounding::halfUp($this->total * $this->deposit_pct / 100);
    }

    public function paymentStateWords(): string
    {
        $balance = $this->balance();

        return match (true) {
            $balance <= 0 => 'Paid in full',
            $balance >= $this->total => 'Awaiting deposit',
            default => 'Deposit received',
        };
    }

    /**
     * Kinds of payment links that are still open. An already loaded
     * paymentLinks relation is reused so a list does not query per row.
     *
     * @return list<string>
     */
    public function openPaymentKinds(): array
    {
        $links = $this->relationLoaded('paymentLinks')
            ? $this->paymentLinks
            : $this->paymentLinks()->where('status', PaymentLinkStatus::Open)->get();

        return $links
            ->filter(fn (PaymentLink $link): bool => $link->status === PaymentLinkStatus::Open)
            ->map(fn (PaymentLink $link): string => $link->kind->value)
            ->values()
            ->all();
    }

    public function commissionAmount(): int
    {
        if ($this->commission_pct === null) {
            return 0;
        }

        return Rounding::halfUp($this->total * $this->commission_pct / 100);
    }

    /**
     * Latest cap approve/reject row, if any. A new episode starts after this id
     * (same-second payments must not reuse the previous blocked entry).
     */
    public function latestCommissionCapDecision(): ?ChangeHistory
    {
        return $this->history()
            ->whereIn('event', ['booking.commission_approved', 'booking.commission_rejected'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function holdExpired(): bool
    {
        if ($this->status !== BookingStatus::Requested) {
            return false;
        }

        $this->loadMissing('bookingRequest');

        return $this->bookingRequest?->hold_expired_at !== null;
    }

    public function occupiesInventory(): bool
    {
        return $this->status->holdsInventory() && ! $this->holdExpired();
    }

    public function cabinLabel(): string
    {
        if ($this->type === BookingType::Charter) {
            return 'Full property';
        }

        $this->loadMissing('cabin');

        $cabin = $this->cabin;

        return $cabin instanceof Room ? $cabin->label : 'Cabin';
    }

    public function historyLabel(): string
    {
        return $this->displayReference() ?? 'booking';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWithGuestSummary(Builder $query): void
    {
        $query
            ->withCount('guests')
            ->withCount(['guests as guests_complete_count' => fn ($guests) => $guests->complete()]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWithChargesSummary(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('bookings.*');
        }

        $query
            ->selectRaw('('.self::extrasTotalSql().') as extras_amount_sum')
            ->selectRaw('('.self::feesCollectedSql().') as fees_collected_sum')
            ->selectRaw('('.self::pngPendingCountSql().') as png_pending_count_agg');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeWithLedgerAggregates(Builder $query): void
    {
        $query
            ->withSum(
                ['payments as payments_paid_sum' => fn ($payments) => $payments->countingAsPaid()],
                'amount',
            )
            ->withSum(
                ['payments as payments_pledged_sum' => fn ($payments) => $payments->where('status', PaymentStatus::AwaitingWire)],
                'amount',
            )
            ->withCount('payments')
            ->withMin([
                'payments as awaiting_wire_created_at' => fn ($payments) => $payments->where('status', PaymentStatus::AwaitingWire),
            ], 'created_at');
    }

    private function awaitingWireStartedAt(): ?CarbonImmutable
    {
        if (array_key_exists('awaiting_wire_created_at', $this->getAttributes())) {
            $aggregated = $this->getAttribute('awaiting_wire_created_at');

            if ($aggregated instanceof \DateTimeInterface) {
                return CarbonImmutable::instance($aggregated);
            }

            if (is_string($aggregated) && $aggregated !== '') {
                return CarbonImmutable::parse($aggregated);
            }

            return null;
        }

        $this->loadMissing('payments');

        $started = $this->payments
            ->first(fn (Payment $payment): bool => $payment->status === PaymentStatus::AwaitingWire)
            ?->created_at;

        return $started instanceof \DateTimeInterface
            ? CarbonImmutable::instance($started)
            : null;
    }

    /**
     * Bookings-index visibility: own-records unless `bookings.view_all`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermission(Permission::BookingsViewAll)) {
            return;
        }

        $query->where($query->qualifyColumn('owner_id'), $user->id);
    }

    /**
     * Galápagos calendar window on the joined `departures.date` column.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDepartingBetween(Builder $query, ?string $from, ?string $to): void
    {
        $query
            ->when(is_string($from) && $from !== '', fn (Builder $inner) => $inner->whereDate('departures.date', '>=', $from))
            ->when(is_string($to) && $to !== '', fn (Builder $inner) => $inner->whereDate('departures.date', '<=', $to));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfSegment(Builder $query, BookingSegment $segment): void
    {
        if ($segment === BookingSegment::Charter) {
            $query->where('type', BookingType::Charter);

            return;
        }

        $b2b = array_values(array_map(
            fn (MainChannel $channel): string => $channel->value,
            array_filter(
                MainChannel::cases(),
                fn (MainChannel $channel): bool => $channel->segment() === BookingSegment::B2B,
            ),
        ));

        $query->where('type', BookingType::Cabin);

        if ($segment === BookingSegment::B2B) {
            $query->whereIn('main_channel', $b2b);

            return;
        }

        $query->whereNotIn('main_channel', $b2b);
    }
}
