<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\BehaviouralEventName;
use App\Models\BehaviouralEvent;
use App\Models\Contact;
use App\Models\Property;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Iso;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

final class EngineActivity
{
    /**
     * @param  array{from?: string, to?: string, name?: string, identified?: bool}  $filters
     * @return array{
     *     rows: LengthAwarePaginator<int, array{at: string, name: string, contact: string, contact_id: int|null, detail: string, side: string}>,
     *     kpis: array{
     *         events_today: int,
     *         identified: int,
     *         anonymous: int,
     *         inventory_touching: int,
     *         web_hold_minutes: int,
     *         web_hold_extension_minutes: int
     *     }
     * }
     */
    public static function page(array $filters, int $perPage): array
    {
        $query = self::filtered(BehaviouralEvent::query(), $filters);

        /** @var Paginator<int, BehaviouralEvent> $events */
        $events = $query
            ->with('contact')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $names = self::propertyNames($events->getCollection()->all());

        $events->setCollection($events->getCollection()->map(
            fn (BehaviouralEvent $event): array => self::format($event, $names),
        ));

        return [
            'rows' => $events,
            'kpis' => self::kpis($filters),
        ];
    }

    /**
     * @param  array{from?: string, to?: string, name?: string, identified?: bool}  $filters
     * @return array{
     *     events_today: int,
     *     identified: int,
     *     anonymous: int,
     *     inventory_touching: int,
     *     web_hold_minutes: int,
     *     web_hold_extension_minutes: int
     * }
     */
    public static function kpis(array $filters): array
    {
        $today = BusinessTime::now()->toDateString();
        $inventory = array_map(
            fn (BehaviouralEventName $name): string => $name->value,
            array_values(array_filter(
                BehaviouralEventName::cases(),
                fn (BehaviouralEventName $name): bool => $name->touchesInventory(),
            )),
        );

        $base = self::filtered(BehaviouralEvent::query(), $filters);

        $holds = app(CurrentConfig::class)->businessRules()->holds;

        return [
            'events_today' => (int) self::filtered(
                BehaviouralEvent::query()
                    ->whereBetween('occurred_at', [
                        BusinessTime::dayStartUtc($today),
                        BusinessTime::dayEndUtc($today),
                    ]),
                $filters,
                withDates: false,
            )->count(),
            'identified' => (int) (clone $base)->whereNotNull('contact_id')->count(),
            'anonymous' => (int) (clone $base)->whereNull('contact_id')->count(),
            'inventory_touching' => (int) (clone $base)->whereIn('name', $inventory)->count(),
            'web_hold_minutes' => $holds->webMinutes,
            'web_hold_extension_minutes' => $holds->webExtensionMinutes,
        ];
    }

    /**
     * @param  Builder<BehaviouralEvent>  $query
     * @param  array{from?: string, to?: string, name?: string, identified?: bool}  $filters
     * @return Builder<BehaviouralEvent>
     */
    private static function filtered(
        Builder $query,
        array $filters,
        bool $withDates = true,
        bool $withIdentified = true,
    ): Builder {
        if ($withDates && isset($filters['from'])) {
            $query->where('occurred_at', '>=', BusinessTime::dayStartUtc($filters['from']));
        }

        if ($withDates && isset($filters['to'])) {
            $query->where('occurred_at', '<=', BusinessTime::dayEndUtc($filters['to']));
        }

        if (isset($filters['name'])) {
            $query->where('name', $filters['name']);
        }

        if ($withIdentified && array_key_exists('identified', $filters)) {
            $filters['identified']
                ? $query->whereNotNull('contact_id')
                : $query->whereNull('contact_id');
        }

        return $query;
    }

    /**
     * @param  list<BehaviouralEvent>  $events
     * @return array<string, string>
     */
    private static function propertyNames(array $events): array
    {
        $legacy = 'itiner'.'ary_code';
        $codes = [];

        foreach ($events as $event) {
            $code = $event->params['property_code'] ?? $event->params[$legacy] ?? null;

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        if ($codes === []) {
            return [];
        }

        return Property::query()
            ->whereIn('code', array_values(array_unique($codes)))
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array{at: string, name: string, contact: string, contact_id: int|null, detail: string, side: string}
     */
    private static function format(BehaviouralEvent $event, array $names): array
    {
        $legacy = 'itiner'.'ary_code';
        $raw = $event->params['property_code'] ?? $event->params[$legacy] ?? null;
        $code = is_string($raw) ? $raw : null;

        return [
            'at' => Iso::utc($event->occurred_at),
            'name' => $event->name->value,
            'contact' => $event->contact instanceof Contact ? $event->contact->name : 'anonymous',
            'contact_id' => $event->contact instanceof Contact ? $event->contact->id : null,
            'detail' => BehaviouralEventDetail::make(
                $event->name,
                $event->params,
                propertyName: $code !== null ? ($names[$code] ?? null) : null,
            ),
            'side' => $event->name->side(),
        ];
    }
}
