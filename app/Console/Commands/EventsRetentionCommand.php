<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BehaviouralEvent;
use App\Models\BehaviouralEventDaily;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class EventsRetentionCommand extends Command
{
    protected $signature = 'iconic:events-retention {--dry-run : Print counts and write nothing}';

    protected $description = 'Roll raw behavioural events into daily counts and delete expired anonymous sessions (L6, LEG-002)';

    public function handle(CurrentConfig $config): int
    {
        $rules = $config->businessRules()->retention;
        $today = BusinessTime::now();
        $dry = (bool) $this->option('dry-run');

        $rawCutoff = $today->subMonths($rules->behaviouralRawMonths)->startOfDay()->utc();
        $unstitchedCutoff = $today->subDays($rules->behaviouralUnstitchedDays)->startOfDay()->utc();

        $stitched = $this->rollup(
            BehaviouralEvent::query()
                ->whereNotNull('contact_id')
                ->where('occurred_at', '<', $rawCutoff),
            $dry,
        );

        $unstitched = $this->rollup(
            BehaviouralEvent::query()
                ->whereNull('contact_id')
                ->where('occurred_at', '<', $unstitchedCutoff),
            $dry,
        );

        $this->info(sprintf(
            '%s %d stitched event(s) older than %d months and %d unstitched event(s) older than %d days.',
            $dry ? 'Would roll up' : 'Rolled up',
            $stitched,
            $rules->behaviouralRawMonths,
            $unstitched,
            $rules->behaviouralUnstitchedDays,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Builder<BehaviouralEvent>  $query
     */
    private function rollup(Builder $query, bool $dry): int
    {
        $buckets = [];
        $ids = [];

        $query->orderBy('id')->each(function (BehaviouralEvent $event) use (&$buckets, &$ids): void {
            $date = $event->occurred_at->timezone(BusinessTime::zone())->toDateString();
            $name = $event->name->value;
            $code = is_string($event->params['itinerary_code'] ?? null)
                ? $event->params['itinerary_code']
                : '';
            $key = $date."\0".$name."\0".$code;
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            $ids[] = $event->id;
        });

        if ($ids === []) {
            return 0;
        }

        if ($dry) {
            return count($ids);
        }

        $now = now();

        foreach ($buckets as $key => $count) {
            [$date, $name, $code] = explode("\0", $key, 3);

            $row = BehaviouralEventDaily::query()->firstOrNew([
                'date' => $date,
                'name' => $name,
                'itinerary_code' => $code,
            ]);

            $row->count = (int) $row->count + $count;
            $row->updated_at = $now;
            $row->save();
        }

        BehaviouralEvent::query()->whereIn('id', $ids)->delete();

        return count($ids);
    }
}
