<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Agencies\CreateAgencyUser;
use App\Actions\Agencies\DecideAgency;
use App\Actions\Agencies\InviteAgencyUser;
use App\Actions\Agencies\RegisterAgency;
use App\Actions\Agencies\ResumeAgencyPortal;
use App\Actions\Agencies\SuspendAgencyPortal;
use App\Actions\Agencies\UpdateAgency;
use App\Actions\Agencies\UpdateAgencyUser;
use App\Enums\AgencyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\DecideAgencyRequest;
use App\Http\Requests\Rms\IndexAgenciesRequest;
use App\Http\Requests\Rms\IndexPortalActivityRequest;
use App\Http\Requests\Rms\ResumeAgencyPortalRequest;
use App\Http\Requests\Rms\StoreAgencyRequest;
use App\Http\Requests\Rms\StoreAgencyUserRequest;
use App\Http\Requests\Rms\SuspendAgencyPortalRequest;
use App\Http\Requests\Rms\UpdateAgencyRequest;
use App\Http\Requests\Rms\UpdateAgencyUserRequest;
use App\Http\Resources\Rms\AgencyPortalPreviewResource;
use App\Http\Resources\Rms\AgencyResource;
use App\Http\Resources\Rms\PortalActivityResource;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\ChangeHistory;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Agencies\AgencyBookingWindow;
use App\Support\Commissions\CommissionKpis;
use App\Support\Portal\PortalActivity;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AgencyController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\AgencyResource>, meta: array{kpis: array{approved_agencies: int, registrations_to_review: int, agency_revenue: int, commission_accrued: int, commission_payable: int, commission_paid: int, agency_approval_business_days: int, commission_payable_days: int, commission_cap_pct: int, commission_default_pct: int}}}',
    )]
    public function index(IndexAgenciesRequest $request, CurrentConfig $config): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Agency::class);

        $search = $request->validated('q');
        $from = self::dateQuery($request->validated('from'));
        $to = self::dateQuery($request->validated('to'));

        $agencies = Agency::query()
            ->with(['users', 'decidedBy', 'bookings.departure', 'bookings.commissionPayout'])
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', AgencyStatus::from((string) $request->validated('status'))),
            )
            ->when(is_string($search) && $search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'like', $like)
                        ->orWhere('contact', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('network', 'like', $like)
                        ->orWhere('reference', 'like', $like);
                });
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Agency $agency): bool => AgencyBookingWindow::visible($agency, $from, $to))
            ->values();

        $rules = $config->businessRules();
        $approved = Agency::query()->where('status', AgencyStatus::Approved)->with('bookings.departure')->get();
        $totals = AgencyBookingWindow::stats(
            $approved->flatMap(
                fn (Agency $agency) => AgencyBookingWindow::inRange($agency->bookings, $from, $to),
            ),
        );
        $commission = CommissionKpis::forApproved($from, $to, $rules);

        return AgencyResource::collection($agencies)->additional([
            'meta' => [
                'kpis' => [
                    'approved_agencies' => $approved->count(),
                    'registrations_to_review' => Agency::query()->where('status', AgencyStatus::Pending)->count(),
                    'agency_revenue' => $totals['revenue'],
                    'commission_accrued' => $commission['commission_accrued'],
                    'commission_payable' => $commission['commission_payable'],
                    'commission_paid' => $commission['commission_paid'],
                    'agency_approval_business_days' => $rules->sla->agencyApprovalBusinessDays,
                    'commission_payable_days' => $rules->commission->payableDaysAfterCheckOut,
                    'commission_cap_pct' => $rules->commission->capPct,
                    'commission_default_pct' => $rules->commission->defaultPct,
                ],
            ],
        ]);
    }

    private static function dateQuery(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function store(StoreAgencyRequest $request, RegisterAgency $action): JsonResponse
    {
        $this->authorize('create', Agency::class);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $agency = $action->handle($request->validated(), $actor);

        return (new AgencyResource($agency))->response()->setStatusCode(201);
    }

    public function show(Agency $agency): AgencyResource
    {
        $this->authorize('view', $agency);

        $resource = new AgencyResource($agency);
        $resource->detailed = true;

        return $resource;
    }

    public function update(UpdateAgencyRequest $request, Agency $agency, UpdateAgency $action): AgencyResource
    {
        $this->authorize('update', $agency);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new AgencyResource($action->handle($agency, $request->validated(), $actor));
    }

    public function decide(DecideAgencyRequest $request, Agency $agency, DecideAgency $action): AgencyResource
    {
        $this->authorize('decide', $agency);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new AgencyResource($action->handle($agency, $request->validated(), $actor));
    }

    public function storeUser(StoreAgencyUserRequest $request, Agency $agency, CreateAgencyUser $action): JsonResponse
    {
        $this->authorize('manageUsers', $agency);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        /** @var array{name: string, email: string} $data */
        $data = $request->validated();

        return (new AgencyResource($action->handle($agency, $data, $actor)))->response()->setStatusCode(201);
    }

    public function updateUser(
        UpdateAgencyUserRequest $request,
        Agency $agency,
        AgencyUser $user,
        UpdateAgencyUser $action,
    ): AgencyResource {
        $this->authorize('manageUsers', $agency);

        if ($user->agency_id !== $agency->id) {
            abort(404);
        }

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        /** @var array{name?: string, status?: string} $data */
        $data = $request->validated();

        return new AgencyResource($action->handle($agency, $user, $data, $actor));
    }

    public function portalPreview(Agency $agency): AgencyPortalPreviewResource
    {
        $this->authorize('viewPortalPreview', $agency);

        return new AgencyPortalPreviewResource($agency);
    }

    public function suspendPortal(SuspendAgencyPortalRequest $request, Agency $agency, SuspendAgencyPortal $action): AgencyResource
    {
        $this->authorize('managePortalAccess', $agency);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new AgencyResource($action->handle($agency, (string) $request->validated('reason'), $actor));
    }

    public function resumePortal(ResumeAgencyPortalRequest $request, Agency $agency, ResumeAgencyPortal $action): AgencyResource
    {
        $this->authorize('managePortalAccess', $agency);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new AgencyResource($action->handle($agency, (string) $request->validated('reason'), $actor));
    }

    public function inviteUser(Request $request, Agency $agency, AgencyUser $user, InviteAgencyUser $action): JsonResponse
    {
        $this->authorize('manageUsers', $agency);

        if ($user->agency_id !== $agency->id) {
            abort(404);
        }

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->handle($user, $actor);

        return response()->json(['message' => 'Invitation sent.']);
    }

    public function portalActivity(IndexPortalActivityRequest $request, Agency $agency): AnonymousResourceCollection
    {
        $this->authorize('viewPortalActivity', $agency);

        $page = ChangeHistory::query()
            ->where('subject_type', $agency->getMorphClass())
            ->where('subject_id', $agency->id)
            ->whereIn('event', PortalActivity::EVENTS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        $ids = [];

        foreach ($page->getCollection() as $entry) {
            $userId = PortalActivity::userId($entry->getAttribute('context'));

            if ($userId !== null) {
                $ids[] = $userId;
            }
        }

        $users = AgencyUser::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');

        $shaped = $page->through(
            fn (ChangeHistory $entry): array => PortalActivity::present($entry, $users),
        );

        return PortalActivityResource::collection($shaped);
    }
}
