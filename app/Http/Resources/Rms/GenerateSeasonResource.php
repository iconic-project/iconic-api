<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{created: list<string>, skipped: list<array{property: string, date: string}>} $resource
 */
class GenerateSeasonResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{created: list<string>, skipped: list<array{property: string, date: string}>}
     */
    public function toArray(Request $request): array
    {
        /** @var array{created: list<string>, skipped: list<array{property: string, date: string}>} $payload */
        $payload = $this->resource;

        return $payload;
    }
}
