<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Enums\DepartureStatus;
use App\Enums\EngineLabelCode;
use App\Models\Agency;
use App\Models\Departure;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Inventory\DepartureSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     id: int,
 *     itinerary: string,
 *     property: string,
 *     embark: string,
 *     disembark: string,
 *     festive: bool,
 *     rate_year: int,
 *     status: DepartureStatus,
 *     label: array{code: EngineLabelCode, text: string},
 *     net_rates: array{suite_pp: int, owner_pp: int}
 * } $resource
 */
class PortalAvailabilityResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(mixed $resource)
    {
        if (is_array($resource) && array_key_exists('departure', $resource)) {
            /** @var array{departure: Departure, snapshot: DepartureSnapshot, agency: Agency, rates: RatesDocument} $resource */
            $resource = self::shape($resource);
        }

        parent::__construct($resource);
    }

    /**
     * @return array{
     *     id: int,
     *     itinerary: string,
     *     property: string,
     *     embark: string,
     *     disembark: string,
     *     festive: bool,
     *     rate_year: int,
     *     status: DepartureStatus,
     *     label: array{code: EngineLabelCode, text: string},
     *     net_rates: array{suite_pp: int, owner_pp: int}
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * @param  array{departure: Departure, snapshot: DepartureSnapshot, agency: Agency, rates: RatesDocument}  $row
     * @return array{
     *     id: int,
     *     itinerary: string,
     *     property: string,
     *     embark: string,
     *     disembark: string,
     *     festive: bool,
     *     rate_year: int,
     *     status: DepartureStatus,
     *     label: array{code: EngineLabelCode, text: string},
     *     net_rates: array{suite_pp: int, owner_pp: int}
     * }
     */
    private static function shape(array $row): array
    {
        $departure = $row['departure'];
        $snapshot = $row['snapshot'];
        $agency = $row['agency'];
        $rates = $row['rates'];

        $year = $rates->year((int) $departure->date->format('Y'));
        $festive = (bool) $departure->festive;

        return [
            'id' => (int) $departure->id,
            'itinerary' => $departure->itinerary->code,
            'property' => $departure->property->code,
            'embark' => $departure->date->toDateString(),
            'disembark' => $departure->returnDate()->toDateString(),
            'festive' => $festive,
            'rate_year' => (int) $departure->date->format('Y'),
            'status' => $departure->status,
            'label' => [
                'code' => EngineLabelCode::from($snapshot->engineLabel['code']),
                'text' => $snapshot->engineLabel['text'],
            ],
            'net_rates' => [
                'suite_pp' => (int) ($year === null ? 0 : $agency->netOf($year->suitePp)),
                'owner_pp' => (int) ($year === null ? 0 : $agency->netOf($year->ownerPp)),
            ],
        ];
    }
}
