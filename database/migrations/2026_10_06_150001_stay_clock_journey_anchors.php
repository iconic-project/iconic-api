<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DML only. Journey step anchors follow the stay.
 * change_history rows for the journey are left as they were written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $steps = DB::table('journey_steps')->select(['id', 'delay'])->orderBy('id')->get();

        foreach ($steps as $step) {
            $delay = json_decode((string) $step->delay, true);

            if (! is_array($delay)) {
                continue;
            }

            $anchor = $delay['anchor'] ?? null;
            $next = match ($anchor) {
                'departure' => 'arrival',
                'return' => 'check_out',
                default => null,
            };

            if ($next === null) {
                continue;
            }

            $delay['anchor'] = $next;

            DB::table('journey_steps')->where('id', $step->id)->update([
                'delay' => json_encode($delay),
            ]);
        }

        $segments = DB::table('segments')->select(['id', 'conditions', 'sentence'])->orderBy('id')->get();

        foreach ($segments as $segment) {
            $conditions = json_decode((string) $segment->conditions, true);

            if (! is_array($conditions) || ! is_array($conditions['items'] ?? null)) {
                continue;
            }

            $changed = false;

            foreach ($conditions['items'] as $index => $item) {
                if (! is_array($item) || ($item['field'] ?? null) !== 'festive_departure_views') {
                    continue;
                }

                $conditions['items'][$index] = [
                    'field' => 'event_count',
                    'operator' => $item['operator'] ?? 'gte',
                    'value' => $item['value'] ?? 1,
                    'event' => 'view_departure',
                    'within_days' => $item['within_days'] ?? null,
                ];
                $changed = true;
            }

            $sentence = $segment->sentence === 'Viewed a festive departure.'
                ? 'Viewed a departure.'
                : $segment->sentence;
            $sentence = $sentence === 'A guest on one of their bookings is aged 6 to 17 at departure.'
                ? 'A guest on one of their bookings is aged 6 to 17 at arrival.'
                : $sentence;

            if (! $changed && $sentence === $segment->sentence) {
                continue;
            }

            DB::table('segments')->where('id', $segment->id)->update([
                'conditions' => json_encode($conditions),
                'sentence' => $sentence,
            ]);
        }
    }

    public function down(): void
    {
        // Anchors already stored under the stay names stay.
    }
};
