<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Enums\SurveyQuestionType;
use App\Models\BookingAccessToken;
use App\Support\GuestExperience\SurveyPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     reference: string,
 *     check_in: string,
 *     property_name: string,
 *     questions: list<array{key: string, label: string, type: SurveyQuestionType, min: int|null, max: int|null}>,
 *     guests: list<array{id: int, first_name: string, last_name: string, responded: bool}>
 * } $resource
 */
class SurveyResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(mixed $resource)
    {
        if ($resource instanceof BookingAccessToken) {
            $resource = SurveyPage::forToken($resource);
        }

        parent::__construct($resource);
    }

    /**
     * @return array{
     *     reference: string,
     *     check_in: string,
     *     property_name: string,
     *     questions: list<array{key: string, label: string, type: SurveyQuestionType, min: int|null, max: int|null}>,
     *     guests: list<array{id: int, first_name: string, last_name: string, responded: bool}>
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
