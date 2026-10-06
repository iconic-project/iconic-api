<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\DealStage;
use App\Enums\DealType;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Payments\PaymentsKpis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PipelineBoard
{
    /**
     * @param  array{owner?: string|null, type?: string|null, q?: string|null}  $filters
     * @return array{columns: list<array<string, mixed>>, meta: array{kpis: array<string, int>}}
     */
    public static function build(User $actor, array $filters): array
    {
        $rules = app(CurrentConfig::class)->businessRules();
        $rows = self::rows($actor, $filters);
        $columns = [];

        foreach (DealStage::columns() as $stage) {
            $columns[$stage->value] = [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'owner' => $stage->owner(),
                'sla' => $stage->isOpen() ? DealSla::label($stage, $rules) : 'SYSTEM-SET',
                'total' => 0,
                'weighted_total' => $stage->weightsForecast() ? 0 : null,
                'deals' => [],
            ];
        }

        foreach ($rows as $row) {
            $stage = DealStage::tryFrom((string) ($row->projected_stage ?? ''));

            if (! $stage instanceof DealStage) {
                continue;
            }
            $value = (int) $row->value_amount;
            $entered = CarbonImmutable::parse((string) $row->stage_entered_at);
            $slaState = $stage->isOpen()
                ? DealSla::assess($stage, $entered, CarbonImmutable::now(), $rules)
                : null;
            $bound = $row->booking_id !== null || $row->group_id !== null;
            $ownerId = $row->owner_id !== null ? (int) $row->owner_id : null;

            $columns[$stage->value]['total'] += $value;

            if ($stage->weightsForecast()) {
                $columns[$stage->value]['weighted_total'] += DealProbabilities::weighted($value, $stage, $rules->crm->pipeline);
            }

            $columns[$stage->value]['deals'][] = [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'contact' => [
                    'id' => (int) $row->contact_id,
                    'name' => (string) $row->contact_name,
                ],
                'type' => (string) $row->type,
                'owner' => $ownerId === null ? null : [
                    'id' => $ownerId,
                    'name' => (string) $row->owner_name,
                ],
                'value' => $value,
                'value_label' => (string) $row->value_label,
                'sla_state' => $slaState,
                'booking' => self::cardBooking($row),
                'may_move' => self::mayMove($actor, $ownerId, $bound),
            ];
        }

        return [
            'columns' => array_values($columns),
            'meta' => ['kpis' => self::kpis()],
        ];
    }

    /**
     * @return array{
     *     collected: int,
     *     scheduled_in: int,
     *     awaiting_first_payment: int,
     *     open_pipeline_count: int,
     *     open_pipeline_value: int,
     *     weighted_forecast: int,
     *     overdue: int
     * }
     */
    public static function kpis(): array
    {
        $ledger = PaymentsKpis::ledger();
        $rules = app(CurrentConfig::class)->businessRules()->crm->pipeline;
        $stage = '('.DealStages::stageSql().')';
        $value = '('.DealStages::valueSql().')';
        $open = "'NEW_LEAD','QUALIFYING','QUOTED','NEGOTIATION','DEPOSIT_PENDING'";
        $weight = "CASE {$stage}
            WHEN 'NEW_LEAD' THEN {$rules->probabilityNewLead}
            WHEN 'QUALIFYING' THEN {$rules->probabilityQualifying}
            WHEN 'QUOTED' THEN {$rules->probabilityQuoted}
            WHEN 'NEGOTIATION' THEN {$rules->probabilityNegotiation}
            WHEN 'DEPOSIT_PENDING' THEN {$rules->probabilityDepositPending}
            ELSE 0 END";

        $row = DB::table('deals')->selectRaw(
            "COALESCE(SUM(CASE WHEN {$stage} IN ({$open}) THEN 1 ELSE 0 END), 0) AS open_count,
             COALESCE(SUM(CASE WHEN {$stage} IN ({$open}) THEN {$value} ELSE 0 END), 0) AS open_value,
             COALESCE(SUM(CASE WHEN {$stage} IN ({$open}) THEN ROUND({$value} * ({$weight}) / 100) ELSE 0 END), 0) AS weighted",
        )->first();

        return [
            'collected' => $ledger['collected'],
            'scheduled_in' => PaymentsKpis::scheduledIn(),
            'awaiting_first_payment' => $ledger['pending'],
            'open_pipeline_count' => (int) ($row->open_count ?? 0),
            'open_pipeline_value' => (int) ($row->open_value ?? 0),
            'weighted_forecast' => (int) ($row->weighted ?? 0),
            'overdue' => $ledger['overdue_amount'],
        ];
    }

    /**
     * @param  array{owner?: string|null, type?: string|null, q?: string|null}  $filters
     * @return list<object>
     */
    private static function rows(User $actor, array $filters): array
    {
        $query = DB::table('deals')
            ->join('contacts', 'contacts.id', '=', 'deals.contact_id')
            ->leftJoin('users', 'users.id', '=', 'deals.owner_id')
            ->select([
                'deals.id',
                'deals.title',
                'deals.type',
                'deals.owner_id',
                'deals.contact_id',
                'deals.booking_id',
                'deals.group_id',
                'deals.stage_entered_at',
                'contacts.name as contact_name',
                'users.name as owner_name',
            ])
            ->selectRaw('('.DealStages::stageSql().') as projected_stage')
            ->selectRaw('('.DealStages::valueSql().') as value_amount')
            ->selectRaw('('.DealStages::valueLabelSql().') as value_label')
            ->selectRaw(DealStages::bookingColumnSql('COALESCE(bookings.reference, bookings.request_reference)').' as booking_reference')
            ->selectRaw(DealStages::bookingColumnSql('bookings.status').' as booking_status')
            ->selectRaw(DealStages::bookingColumnSql('bookings.check_in').' as departure_date')
            ->orderBy('deals.id');

        $owner = $filters['owner'] ?? null;

        if ($owner === 'me') {
            $query->where('deals.owner_id', $actor->id);
        } elseif ($owner === 'unassigned') {
            $query->whereNull('deals.owner_id');
        } elseif (is_string($owner) && ctype_digit($owner)) {
            $query->where('deals.owner_id', (int) $owner);
        }

        $type = $filters['type'] ?? null;

        if (is_string($type) && DealType::tryFrom($type) instanceof DealType) {
            $query->where('deals.type', $type);
        }

        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('deals.title', 'like', $like)
                    ->orWhere('contacts.name', 'like', $like);
            });
        }

        return $query->get()->all();
    }

    /**
     * @return array{reference: string, status: string, departure_date: string}|null
     */
    private static function cardBooking(object $row): ?array
    {
        $reference = is_string($row->booking_reference ?? null) ? $row->booking_reference : '';

        if ($reference === '') {
            return null;
        }

        $date = $row->departure_date ?? null;

        return [
            'reference' => $reference,
            'status' => is_string($row->booking_status ?? null) ? $row->booking_status : '',
            'departure_date' => is_string($date) ? substr($date, 0, 10) : '',
        ];
    }

    private static function mayMove(User $actor, ?int $ownerId, bool $bound): bool
    {
        if ($bound || $ownerId === null || ! $actor->hasPermission(Permission::PipelineMoveStage)) {
            return false;
        }

        return $ownerId === $actor->id || $actor->hasPermission(Permission::RecordsActOnAny);
    }
}
