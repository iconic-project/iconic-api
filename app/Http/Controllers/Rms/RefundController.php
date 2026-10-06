<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Refunds\DecideRefund;
use App\Actions\Refunds\ExecuteRefund;
use App\Enums\RefundRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\DecideRefundRequest;
use App\Http\Requests\Rms\ExecuteRefundRequest;
use App\Http\Requests\Rms\IndexRefundsRequest;
use App\Http\Resources\Rms\RefundRequestResource;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RefundController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Rms\\RefundRequestResource>, meta: array{rules: array{refund_business_days: int}}}',
    )]
    public function index(IndexRefundsRequest $request, CurrentConfig $config): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RefundRequest::class);

        $refunds = RefundRequest::query()
            ->with(['booking.contact', 'booking.property', 'decidedBy'])
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where(
                    'status',
                    RefundRequestStatus::from((string) $request->validated('status')),
                ),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $query) => $query->whereDate('cancelled_at', '>=', (string) $request->validated('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $query) => $query->whereDate('cancelled_at', '<=', (string) $request->validated('to')),
            )
            ->orderByDesc('id')
            ->get();

        return RefundRequestResource::collection($refunds)->additional([
            'meta' => [
                'rules' => [
                    'refund_business_days' => $config->businessRules()->sla->refundBusinessDays,
                ],
            ],
        ]);
    }

    public function decide(
        DecideRefundRequest $request,
        RefundRequest $refund,
        DecideRefund $action,
    ): RefundRequestResource {
        $this->authorize('decide', $refund);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new RefundRequestResource($action->handle($refund, $request->validated(), $actor));
    }

    public function execute(
        ExecuteRefundRequest $request,
        RefundRequest $refund,
        ExecuteRefund $action,
    ): RefundRequestResource {
        $this->authorize('execute', $refund);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return new RefundRequestResource($action->handle($refund, $request->validated(), $actor));
    }
}
