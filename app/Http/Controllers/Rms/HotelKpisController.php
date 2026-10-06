<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Enums\ChannelOfOriginGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\HotelKpisRequest;
use App\Http\Resources\Rms\HotelKpisResource;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Metrics\HotelKpis;
use Carbon\CarbonImmutable;

final class HotelKpisController extends Controller
{
    public function __invoke(HotelKpisRequest $request, HotelKpis $kpis, CurrentConfig $config): HotelKpisResource
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $today = BusinessTime::now()->toDateString();
        $pickupDays = $config->businessRules()->reports->pickupDays;
        $propertyId = $request->propertyId();
        $roomTypeId = $request->roomTypeId();
        $channel = $request->channel();
        $periods = [];

        foreach ($this->ranges($today) as $key => $range) {
            $current = $kpis->measure($range['from'], $range['until'], $today, $pickupDays, $propertyId, $roomTypeId, $channel);
            $lastFrom = CarbonImmutable::parse($range['from'])->subYear()->toDateString();
            $lastUntil = CarbonImmutable::parse($range['until'])->subYear()->toDateString();
            $lastYear = $kpis->measure($lastFrom, $lastUntil, $today, $pickupDays, $propertyId, $roomTypeId, $channel);

            $periods[] = [
                'key' => $key,
                'from' => $range['from'],
                'to' => $range['until'],
                'kpis' => $this->card($current),
                'last_year' => $lastYear['has_activity'] ? $this->card($lastYear) : null,
            ];
        }

        return new HotelKpisResource([
            'today' => $today,
            'pickup_days' => $pickupDays,
            'property' => $propertyId,
            'room_type' => $roomTypeId,
            'channel' => $channel instanceof ChannelOfOriginGroup ? $channel->value : null,
            'periods' => $periods,
        ]);
    }

    /**
     * @return array<string, array{from: string, until: string}>
     */
    private function ranges(string $today): array
    {
        $day = CarbonImmutable::parse($today);

        return [
            'this_month' => [
                'from' => $day->startOfMonth()->toDateString(),
                'until' => $day->startOfMonth()->addMonth()->toDateString(),
            ],
            'next_30' => [
                'from' => $today,
                'until' => $day->addDays(30)->toDateString(),
            ],
            'next_90' => [
                'from' => $today,
                'until' => $day->addDays(90)->toDateString(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $measured
     * @return array<string, mixed>
     */
    private function card(array $measured): array
    {
        unset($measured['nights']);

        return $measured;
    }
}
