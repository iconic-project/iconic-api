<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Enums\AgencyStatus;
use App\Enums\DealStage;
use App\Enums\JourneyEnrolmentStatus;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\JourneyEnrolment;
use App\Models\JourneyStep;
use App\Support\Agencies\AgencyBookingWindow;
use App\Support\Crm\ContactDerived;
use App\Support\Crm\DealDrawer;
use App\Support\Crm\DealStages;
use App\Support\Iso;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * @mixin Agency
 */
#[SchemaName('B2bPartnerResource')]
class B2bPartnerResource extends JsonResource
{
    public const NO_CONTACT_NOTE = 'No CRM contact matches this agency, so b2b_partner_activation was not enrolled.';

    private const JOURNEY_KEY = 'b2b_partner_activation';

    public static $wrap = null;

    public bool $detailed = false;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     name: string,
     *     status: AgencyStatus,
     *     commission_pct: int,
     *     contact: array{id: int, name: string}|null,
     *     revenue: int,
     *     commission_accrued: int,
     *     enrolment: array{status: JourneyEnrolmentStatus, step: array{position: int, name: string}|null, next_due_at: string|null}|null,
     *     enrolment_note: string|null,
     *     open_deal_count: int
     * }|array{
     *     id: int,
     *     reference: string,
     *     name: string,
     *     status: AgencyStatus,
     *     commission_pct: int,
     *     contact: array{id: int, name: string}|null,
     *     revenue: int,
     *     commission_accrued: int,
     *     enrolment: array{
     *         id: int,
     *         journey_key: string,
     *         contact: array{id: int, name: string, email: string|null},
     *         booking: array{id: int, reference: string|null}|null,
     *         step: array{position: int, name: string, template_key: string}|null,
     *         next_due_at: string|null,
     *         status: JourneyEnrolmentStatus,
     *         exit_reason: string|null,
     *         enrolled_at: string|null,
     *         exited_at: string|null,
     *         sends: list<array{sent_at: string|null, template_key: string, catalogue_key: string|null, delivery_id: int|null}>
     *     }|null,
     *     enrolment_note: string|null,
     *     open_deal_count: int,
     *     deals: list<DealResource>
     * }
     */
    public function toArray(Request $request): array
    {
        $agency = $this->resource;

        if (! $agency instanceof Agency) {
            throw new LogicException('B2B partner resource expected an agency.');
        }

        $agency->loadMissing(['bookings.commissionPayout']);
        $stats = AgencyBookingWindow::stats($agency->bookings);
        $contact = ContactDerived::contactForAgency($agency);
        $enrolment = $contact instanceof Contact ? $this->enrolmentFor($contact) : null;

        $payload = [
            'id' => $agency->id,
            'reference' => $agency->reference,
            'name' => $agency->name,
            'status' => $this->agencyStatus($agency),
            'commission_pct' => $agency->commission_pct,
            'contact' => $contact instanceof Contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
            ] : null,
            'revenue' => $stats['revenue'],
            'commission_accrued' => $stats['commission_accrued'],
            'enrolment' => $this->enrolmentPayload($enrolment, $request),
            'enrolment_note' => $contact instanceof Contact ? null : self::NO_CONTACT_NOTE,
            'open_deal_count' => $this->openDealCount($agency),
        ];

        if (! $this->detailed) {
            return $payload;
        }

        $payload['deals'] = $this->dealHistory($agency);

        return $payload;
    }

    /**
     * @return array{status: JourneyEnrolmentStatus, step: array{position: int, name: string}|null, next_due_at: string|null}|array{
     *     id: int,
     *     journey_key: string,
     *     contact: array{id: int, name: string, email: string|null},
     *     booking: array{id: int, reference: string|null}|null,
     *     step: array{position: int, name: string, template_key: string}|null,
     *     next_due_at: string|null,
     *     status: JourneyEnrolmentStatus,
     *     exit_reason: string|null,
     *     enrolled_at: string|null,
     *     exited_at: string|null,
     *     sends: list<array{sent_at: string|null, template_key: string, catalogue_key: string|null, delivery_id: int|null}>
     * }|null
     */
    private function enrolmentPayload(?JourneyEnrolment $enrolment, Request $request): ?array
    {
        if (! $enrolment instanceof JourneyEnrolment) {
            return null;
        }

        if (! $this->detailed) {
            return $this->enrolmentSummary($enrolment);
        }

        return $this->fullEnrolment($enrolment, $request);
    }

    /**
     * @return array{status: JourneyEnrolmentStatus, step: array{position: int, name: string}|null, next_due_at: string|null}
     */
    private function enrolmentSummary(JourneyEnrolment $enrolment): array
    {
        $enrolment->loadMissing('journey.steps');
        $step = $enrolment->journey->steps->firstWhere('position', $enrolment->position);

        return [
            'status' => $this->enrolmentStatus($enrolment),
            'step' => $step instanceof JourneyStep ? [
                'position' => $step->position,
                'name' => $step->name,
            ] : null,
            'next_due_at' => Iso::utc($enrolment->next_due_at),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     journey_key: string,
     *     contact: array{id: int, name: string, email: string|null},
     *     booking: array{id: int, reference: string|null}|null,
     *     step: array{position: int, name: string, template_key: string}|null,
     *     next_due_at: string|null,
     *     status: JourneyEnrolmentStatus,
     *     exit_reason: string|null,
     *     enrolled_at: string|null,
     *     exited_at: string|null,
     *     sends: list<array{sent_at: string|null, template_key: string, catalogue_key: string|null, delivery_id: int|null}>
     * }
     */
    private function fullEnrolment(JourneyEnrolment $enrolment, Request $request): array
    {
        $resolved = (new CrmJourneyEnrolmentResource($enrolment))->resolve($request);
        $resolved['status'] = $this->enrolmentStatus($enrolment);

        return $resolved;
    }

    private function enrolmentFor(Contact $contact): ?JourneyEnrolment
    {
        return JourneyEnrolment::query()
            ->where('contact_id', $contact->id)
            ->whereHas('journey', function (Builder $query): void {
                $query->where('key', self::JOURNEY_KEY);
            })
            ->with(['contact', 'booking', 'journey.steps', 'sends'])
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->first();
    }

    private function openDealCount(Agency $agency): int
    {
        $open = array_map(
            static fn (DealStage $stage): string => $stage->value,
            DealStage::open(),
        );

        return $this->dealsFor($agency)
            ->whereRaw(
                '('.DealStages::stageSql().') in ('.implode(', ', array_fill(0, count($open), '?')).')',
                $open,
            )
            ->count();
    }

    /**
     * @return list<DealResource>
     */
    private function dealHistory(Agency $agency): array
    {
        $rows = [];

        foreach ($this->dealsFor($agency)->orderByDesc('stage_entered_at')->orderByDesc('id')->get() as $deal) {
            $rows[] = new DealResource(DealDrawer::for($deal));
        }

        return $rows;
    }

    private function agencyStatus(Agency $agency): AgencyStatus
    {
        return $agency->status;
    }

    private function enrolmentStatus(JourneyEnrolment $enrolment): JourneyEnrolmentStatus
    {
        return $enrolment->status;
    }

    /**
     * @return Builder<Deal>
     */
    private function dealsFor(Agency $agency): Builder
    {
        return Deal::query()->whereExists(function (QueryBuilder $query) use ($agency): void {
            $query->selectRaw('1')
                ->from('bookings')
                ->where('bookings.agency_id', $agency->id)
                ->whereNull('bookings.deleted_at')
                ->where(function (QueryBuilder $match): void {
                    $match->where(function (QueryBuilder $booking): void {
                        $booking->whereNotNull('deals.booking_id')
                            ->whereColumn('bookings.id', 'deals.booking_id');
                    })->orWhere(function (QueryBuilder $group): void {
                        $group->whereNotNull('deals.group_id')
                            ->whereColumn('bookings.group_id', 'deals.group_id');
                    });
                });
        });
    }
}
