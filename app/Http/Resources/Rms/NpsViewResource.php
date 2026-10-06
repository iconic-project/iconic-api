<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     kpis: array{average_score: string|null, responses: int, alerts_below: int, review_requests_sent: int},
 *     responses: list<array{booking_reference: string, guest: string, score: int, score_class: 'low'|'neutral'|'high', recommend: int|null, best: string|null, better: string|null, crew: string|null}>,
 *     facts: array{first_expected_survey_on: string|null, survey_hours_after_check_out: int, alert_below: int, review_request_from: int}
 * } $resource
 */
#[SchemaName('NpsViewResource')]
class NpsViewResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     kpis: array{average_score: string|null, responses: int, alerts_below: int, review_requests_sent: int},
     *     responses: list<array{booking_reference: string, guest: string, score: int, score_class: 'low'|'neutral'|'high', recommend: int|null, best: string|null, better: string|null, crew: string|null}>,
     *     facts: array{first_expected_survey_on: string|null, survey_hours_after_check_out: int, alert_below: int, review_request_from: int}
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
