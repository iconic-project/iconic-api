<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     id: int,
 *     title: string,
 *     type: string,
 *     stage: string,
 *     owner: array{id: int, name: string}|null,
 *     value: int,
 *     value_label: string,
 *     sla: array{state: string|null, label: string},
 *     booking: array{
 *         reference: string,
 *         status: string,
 *         check_in: string,
 *         check_out: string,
 *         nights: int,
 *         room_type: string|null,
 *         property_name: string|null,
 *         room: string|null,
 *         charges_total: int,
 *         paid: int,
 *         balance: int,
 *         agency: array{id: int, name: string}|null,
 *         offer_codes: list<string>,
 *         main_channel: string,
 *         channel_of_origin: string,
 *         utm_first: array<string, mixed>|null
 *     }|null,
 *     searches: list<array{at: string, name: string, detail: string}>,
 *     contact_id: int
 * } $resource
 */
#[SchemaName('DealResource')]
class DealResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     type: string,
     *     stage: string,
     *     owner: array{id: int, name: string}|null,
     *     value: int,
     *     value_label: string,
     *     sla: array{state: string|null, label: string},
     *     booking: array{
     *         reference: string,
     *         status: string,
     *         check_in: string,
     *         check_out: string,
     *         nights: int,
     *         room_type: string|null,
     *         property_name: string|null,
     *         room: string|null,
     *         charges_total: int,
     *         paid: int,
     *         balance: int,
     *         agency: array{id: int, name: string}|null,
     *         offer_codes: list<string>,
     *         main_channel: string,
     *         channel_of_origin: string,
     *         utm_first: array<string, mixed>|null
     *     }|null,
     *     searches: list<array{at: string, name: string, detail: string}>,
     *     contact_id: int
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
