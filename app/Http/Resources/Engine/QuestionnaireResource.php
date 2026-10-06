<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Enums\PreferenceQuestionType;
use App\Models\BookingAccessToken;
use App\Support\GuestExperience\QuestionnairePage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     reference: string,
 *     check_in: string,
 *     check_out: string,
 *     property_name: string,
 *     questions: list<array{key: string, label: string, type: PreferenceQuestionType, options: list<string>, restricted: bool, required: bool}>,
 *     guests: list<array{id: int, first_name: string, room: string, answers: array<string, string>}>
 * } $resource
 */
class QuestionnaireResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(mixed $resource)
    {
        if ($resource instanceof BookingAccessToken) {
            $resource = QuestionnairePage::forToken($resource);
        }

        parent::__construct($resource);
    }

    /**
     * @return array{
     *     reference: string,
     *     check_in: string,
     *     check_out: string,
     *     property_name: string,
     *     questions: list<array{key: string, label: string, type: PreferenceQuestionType, options: list<string>, restricted: bool, required: bool}>,
     *     guests: list<array{id: int, first_name: string, room: string, answers: array<string, string>}>
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
