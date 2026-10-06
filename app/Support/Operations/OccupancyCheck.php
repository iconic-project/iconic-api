<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Enums\AlertKind;
use App\Enums\PropertyStatus;
use App\Models\Alert;
use App\Models\Property;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\NightAvailability;
use App\Support\Alerts\AlertKeys;
use App\Support\BusinessTime;

final class OccupancyCheck
{
    public function __construct(
        private readonly RaiseAlert $raise,
        private readonly ResolveAlert $resolve,
        private readonly CurrentConfig $config,
        private readonly NightAvailability $nights,
    ) {}

    public function run(): void
    {
        $rules = $this->config->businessRules()->alerts;
        $start = BusinessTime::calendarDay(BusinessTime::now()->toDateString())->addDay();
        $keys = [];

        Property::query()
            ->where('status', PropertyStatus::Active)
            ->orderBy('id')
            ->each(function (Property $property) use ($rules, $start, &$keys): void {
                /** @var list<array{night: string, pct: int}> $run */
                $run = [];

                for ($offset = 0; $offset < $rules->lowOccupancyDaysBefore; $offset++) {
                    $night = $start->addDays($offset);
                    $row = $this->nights->occupancy($property, $night, $night->addDay());
                    $low = $row['room_nights_available'] > 0 && $row['pct'] < $rules->lowOccupancyPct;

                    if ($low) {
                        $run[] = [
                            'night' => $night->toDateString(),
                            'pct' => $row['pct'],
                        ];

                        continue;
                    }

                    $raised = $this->raiseRun($property, $run, $rules->lowOccupancyMinConsecutiveNights, $rules->lowOccupancyPct);

                    if ($raised !== null) {
                        $keys[] = $raised;
                    }

                    $run = [];
                }

                $raised = $this->raiseRun($property, $run, $rules->lowOccupancyMinConsecutiveNights, $rules->lowOccupancyPct);

                if ($raised !== null) {
                    $keys[] = $raised;
                }
            });

        $this->resolveCleared($keys);
    }

    /**
     * @param  list<array{night: string, pct: int}>  $run
     */
    private function raiseRun(Property $property, array $run, int $minimum, int $threshold): ?string
    {
        if ($run === [] || count($run) < $minimum) {
            return null;
        }

        $first = $run[0]['night'];
        $last = $run[count($run) - 1]['night'];
        $lowest = $run[0]['pct'];

        foreach ($run as $night) {
            if ($night['pct'] < $lowest) {
                $lowest = $night['pct'];
            }
        }
        $key = AlertKeys::occupancyRun($property->id, $first, $last);
        $span = $first === $last ? $first : $first.' to '.$last;

        $this->raise->handle(
            AlertKind::LowOccupancy,
            $key,
            'Low occupancy '.$property->code.' '.$span,
            $property->code.' '.$span.' is '.$lowest.'% sold, below the '.$threshold.'% threshold for '.count($run).' nights.',
        );

        return $key;
    }

    /**
     * @param  list<string>  $keys
     */
    private function resolveCleared(array $keys): void
    {
        $query = Alert::query()
            ->where('kind', AlertKind::LowOccupancy)
            ->unresolved()
            ->orderBy('id');

        if ($keys !== []) {
            $query->whereNotIn('base_key', $keys);
        }

        $query->each(function (Alert $alert): void {
            $this->resolve->handle($alert, 'the run is no longer below the threshold');
        });
    }
}
