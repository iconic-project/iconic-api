<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Departure;
use App\Models\GuestResponse;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Config\Documents\NpsRules;
use App\Support\Stays\StayClock;
use Illuminate\Database\Eloquent\Builder;

final class NpsDashboard
{
    public function __construct(private readonly CurrentConfig $config) {}

    /**
     * @return array{
     *     kpis: array{average_score: string|null, responses: int, alerts_below: int, review_requests_sent: int},
     *     responses: list<array{booking_reference: string, guest: string, score: int, score_class: 'low'|'neutral'|'high', recommend: int|null, best: string|null, better: string|null, crew: string|null}>,
     *     facts: array{first_expected_survey_on: string|null, survey_hours_after_check_out: int, alert_below: int, review_request_from: int}
     * }
     */
    public function present(?string $from, ?string $to): array
    {
        $rules = $this->config->businessRules()->nps;
        $rows = $this->query($from, $to)->get();
        $scores = $rows->map(fn (GuestResponse $row): int => $row->score)->all();
        $count = count($scores);
        $guestIds = $rows->map(fn (GuestResponse $row): int => $row->guest_id)->all();

        return [
            'kpis' => [
                'average_score' => $count === 0 ? null : number_format(array_sum($scores) / $count, 1, '.', ''),
                'responses' => $count,
                'alerts_below' => $rows->filter(fn (GuestResponse $row): bool => $row->score < $rules->alertBelow)->count(),
                'review_requests_sent' => $this->reviewRequests($guestIds),
            ],
            'responses' => $rows->map(fn (GuestResponse $row): array => [
                'booking_reference' => (string) ($row->booking->displayReference() ?? ''),
                'guest' => $row->guest->displayName(),
                'score' => $row->score,
                'score_class' => self::scoreClass($row->score, $rules),
                'recommend' => $row->recommend,
                'best' => $row->best,
                'better' => $row->better,
                'crew' => $row->crew,
            ])->all(),
            'facts' => [
                'first_expected_survey_on' => self::firstExpected($rules),
                'survey_hours_after_check_out' => $rules->surveyHoursAfterCheckOut,
                'alert_below' => $rules->alertBelow,
                'review_request_from' => $rules->reviewRequestFrom,
            ],
        ];
    }

    /**
     * @return 'low'|'neutral'|'high'
     */
    public static function scoreClass(int $score, NpsRules $rules): string
    {
        if ($score < $rules->alertBelow) {
            return 'low';
        }

        if ($score >= $rules->reviewRequestFrom) {
            return 'high';
        }

        return 'neutral';
    }

    public static function firstExpected(NpsRules $rules): ?string
    {
        $departure = Departure::query()->orderBy('date')->orderBy('id')->first();

        if (! $departure instanceof Departure) {
            return null;
        }

        return app(StayClock::class)
            ->checkOutMoment($departure->stayDates())
            ->addHours($rules->surveyHoursAfterCheckOut)
            ->toDateString();
    }

    /**
     * @return Builder<GuestResponse>
     */
    private function query(?string $from, ?string $to): Builder
    {
        $query = GuestResponse::query()
            ->with(['booking', 'guest'])
            ->orderByDesc('responded_at')
            ->orderByDesc('id');

        if ($from !== null) {
            $query->where('responded_at', '>=', BusinessTime::dayStartUtc($from));
        }

        if ($to !== null) {
            $query->where('responded_at', '<=', BusinessTime::dayEndUtc($to));
        }

        return $query;
    }

    /**
     * @param  list<int>  $guestIds
     */
    private function reviewRequests(array $guestIds): int
    {
        if ($guestIds === []) {
            return 0;
        }

        $keys = array_map(fn (int $id): string => 'review:'.$id, $guestIds);

        return Delivery::query()
            ->where('kind', DeliveryKind::ReviewRequest)
            ->whereIn('status', [DeliveryStatus::Queued, DeliveryStatus::Sent])
            ->whereIn('idempotency_key', $keys)
            ->count();
    }
}
