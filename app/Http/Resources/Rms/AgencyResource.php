<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\AgencyUserStatus;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Services\Config\CurrentConfig;
use App\Support\Agencies\AgencyBookingWindow;
use App\Support\Agencies\AgencySla;
use App\Support\Agencies\PortalPreview;
use App\Support\BusinessHours;
use App\Support\Commissions\Accrual;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agency
 */
class AgencyResource extends JsonResource
{
    public static $wrap = null;

    public bool $detailed = false;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     name: string,
     *     contact: string,
     *     email: string,
     *     country: string|null,
     *     network: string|null,
     *     commission_pct: int,
     *     payment_terms: string,
     *     status: string,
     *     requested_at: string,
     *     decided_at: string|null,
     *     decided_by: array{id: int, name: string}|null,
     *     decision_reason: string|null,
     *     portal_suspended: bool,
     *     portal_suspended_at: string|null,
     *     portal_suspended_by: array{id: int, name: string}|null,
     *     portal_suspend_reason: string|null,
     *     sla_business_days_elapsed: int,
     *     sla_breached: bool,
     *     users: list<array{id: int, name: string, email: string, status: AgencyUserStatus, invite_sent_at: string|null, invite_expires_at: string|null, last_login_at: string|null}>,
     *     bookings_count: int,
     *     revenue: int,
     *     commission_accrued: int,
     *     held_bookings_count: int
     * }|array{
     *     id: int,
     *     reference: string,
     *     name: string,
     *     contact: string,
     *     email: string,
     *     country: string|null,
     *     network: string|null,
     *     commission_pct: int,
     *     payment_terms: string,
     *     status: string,
     *     requested_at: string,
     *     decided_at: string|null,
     *     decided_by: array{id: int, name: string}|null,
     *     decision_reason: string|null,
     *     portal_suspended: bool,
     *     portal_suspended_at: string|null,
     *     portal_suspended_by: array{id: int, name: string}|null,
     *     portal_suspend_reason: string|null,
     *     sla_business_days_elapsed: int,
     *     sla_breached: bool,
     *     users: list<array{id: int, name: string, email: string, status: AgencyUserStatus, invite_sent_at: string|null, invite_expires_at: string|null, last_login_at: string|null}>,
     *     revenue: int,
     *     commission_accrued: int,
     *     bookings_count: int,
     *     held_bookings_count: int,
     *     bookings: list<array{id: int, reference: string|null, status: string, total: int, commission_pct: int|null, commission_amount: int, commission_approved: bool, check_in: string, client: string, payable_date: string, accrual_status: string, payout: array{amount: int, paid_on: string, bank_reference: string}|null}>,
     *     portal_preview: array{commission_pct: int, net_rates: list<array{year: int, suite_pp: int, owner_pp: int, charter_week: int}>}
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['users', 'decidedBy', 'portalSuspendedBy']);

        $config = app(CurrentConfig::class);
        $rules = $config->businessRules();
        $hours = BusinessHours::fromDocument($rules);
        $sla = AgencySla::for($this->resource, $hours, $rules);

        $payload = [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'contact' => $this->contact,
            'email' => $this->email,
            'country' => $this->country,
            'network' => $this->network,
            'commission_pct' => $this->commission_pct,
            'payment_terms' => $this->payment_terms,
            'status' => $this->status->value,
            'requested_at' => Iso::utc($this->requested_at),
            'decided_at' => $this->decided_at !== null ? Iso::utc($this->decided_at) : null,
            'decided_by' => $this->decidedBy === null ? null : [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ],
            'decision_reason' => $this->decision_reason,
            'portal_suspended' => $this->resource->isPortalSuspended(),
            'portal_suspended_at' => $this->portal_suspended_at !== null ? Iso::utc($this->portal_suspended_at) : null,
            'portal_suspended_by' => $this->portalSuspendedBy === null ? null : [
                'id' => $this->portalSuspendedBy->id,
                'name' => $this->portalSuspendedBy->name,
            ],
            'portal_suspend_reason' => $this->portal_suspend_reason,
            'sla_business_days_elapsed' => $sla['sla_business_days_elapsed'],
            'sla_breached' => $sla['sla_breached'],
            'users' => $this->users->map(fn (AgencyUser $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'invite_sent_at' => Iso::utc($user->invite_sent_at),
                'invite_expires_at' => Iso::utc($user->invite_expires_at),
                'last_login_at' => Iso::utc($user->last_login_at),
            ])->values()->all(),
        ];

        $this->resource->loadMissing(['bookings.room.roomType', 'bookings.property']);
        $from = is_string($request->query('from')) ? $request->query('from') : null;
        $to = is_string($request->query('to')) ? $request->query('to') : null;
        $forStats = $this->detailed
            ? $this->bookings
            : AgencyBookingWindow::inRange($this->bookings, $from, $to);
        $payload = array_merge($payload, AgencyBookingWindow::stats($forStats));

        if (! $this->detailed) {
            return $payload;
        }

        $this->resource->loadMissing(['bookings.contact', 'bookings.room.roomType', 'bookings.property', 'bookings.commissionPayout']);

        $bookings = $this->bookings;
        $payload['bookings'] = $bookings->map(fn (Booking $booking): array => [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'total' => $booking->total,
            'commission_pct' => $booking->commission_pct,
            'commission_amount' => $booking->commissionAmount(),
            'commission_approved' => $booking->commission_approved,
            'check_in' => $booking->stay()->checkIn()->toDateString(),
            'client' => $booking->contact->name,
            'payable_date' => Accrual::payableDate($booking, $rules)->toDateString(),
            'accrual_status' => Accrual::status($booking, $rules)->value,
            'payout' => $booking->commissionPayout?->toArrayForApi(),
        ])->values()->all();
        $payload['portal_preview'] = PortalPreview::for($this->resource, $config->rates());

        return $payload;
    }
}
