<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingExtra;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\ExtraItem;
use App\Support\History\History;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoExtrasSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $catalogue = app(CurrentConfig::class)->extras();

        DB::transaction(function () use ($catalogue): void {
            $this->addExtra('ANK-2026-0011', $catalogue->find('FLT'), 2);
            $this->addExtra('ANK-2026-0007', $catalogue->find('HPRE'), 1);
            $this->collectPng('ANK-2026-0009');
        });
    }

    private function addExtra(string $reference, ?ExtraItem $item, int $qty): void
    {
        if ($item === null || $item->priceUsd === null) {
            return;
        }

        $booking = Booking::query()->where('reference', $reference)->first();

        if (! $booking instanceof Booking) {
            return;
        }

        $existing = BookingExtra::query()
            ->where('booking_id', $booking->id)
            ->where('code', $item->code)
            ->first();

        if ($existing instanceof BookingExtra) {
            return;
        }

        $extra = BookingExtra::query()->create([
            'booking_id' => $booking->id,
            'code' => $item->code,
            'name' => $item->name,
            'unit' => $item->unit,
            'qty' => $qty,
            'rate_usd' => $item->priceUsd,
            'note' => null,
        ]);

        History::record($booking, 'extra.added', after: [
            'extra_id' => $extra->id,
            'code' => $extra->code,
            'qty' => $extra->qty,
            'rate_usd' => $extra->rate_usd,
            'what' => 'Extra added — '.$extra->name.' × '.$extra->qty,
        ], system: true);
    }

    private function collectPng(string $reference): void {}
}
