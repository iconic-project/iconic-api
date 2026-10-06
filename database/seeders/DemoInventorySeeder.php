<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Blocks\CreateInternalBlock;
use App\Enums\BlockReason;
use App\Enums\ReferenceType;
use App\Models\Departure;
use App\Models\InternalBlock;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\Room;
use App\Services\References\ReferenceService;
use App\Support\Departures\SeedMapper as DepartureSeedMapper;
use App\Support\Itineraries\SeedMapper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class DemoInventorySeeder extends Seeder
{
    public function run(): void
    {
        // Yacht inventory is retired. Hotel seed is HotelSeeder.
        return;

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->itineraryRows() as $row) {
            $attributes = SeedMapper::fromPrototype($row);
            $code = (string) $attributes['code'];
            unset($attributes['code']);

            Itinerary::query()->firstOrCreate(
                ['code' => $code],
                $attributes,
            );
        }

        $properties = Property::query()->get()->keyBy('code');
        $itineraries = Itinerary::query()->get()->keyBy('code');

        foreach ($this->departureRows() as $row) {
            $mapped = DepartureSeedMapper::fromPrototype($row);
            $property = $properties->get($mapped['property_code']);
            $itinerary = $itineraries->get($mapped['itinerary_code']);

            if (! $property instanceof Property || ! $itinerary instanceof Itinerary) {
                throw new RuntimeException(
                    "Demo departure {$mapped['reference']} is missing property {$mapped['property_code']} or itinerary {$mapped['itinerary_code']}.",
                );
            }

            Departure::query()->firstOrCreate(
                [
                    'property_id' => $property->id,
                    'date' => $mapped['date'],
                ],
                [
                    'reference' => $mapped['reference'],
                    'itinerary_id' => $itinerary->id,
                    'status' => $mapped['status'],
                    'urgency_threshold' => $mapped['urgency_threshold'],
                    'waitlist_enabled' => $mapped['waitlist_enabled'],
                    'public_note' => $mapped['public_note'],
                    'festive' => $mapped['festive'],
                ],
            );
        }

        DB::transaction(fn () => app(ReferenceService::class)->ensureAtLeast(ReferenceType::Departure, 16));

        $this->seedDemoBlock();
    }

    private function seedDemoBlock(): void
    {
        if (InternalBlock::query()->where('reference', 'BLK-001')->exists()) {
            return;
        }

        $departure = Departure::query()
            ->where('reference', 'DEP-003')
            ->with('property.cabins')
            ->first();

        if (! $departure instanceof Departure) {
            return;
        }

        $cabins = $departure->property->cabins
            ->filter(fn (Room $cabin): bool => in_array($cabin->code, ['S7', 'S8'], true))
            ->sortBy('sort')
            ->values();
        $stay = $departure->stayDates();

        DB::transaction(function () use ($stay, $cabins): void {
            app(CreateInternalBlock::class)->handle([
                'starts_on' => $stay->checkIn()->toDateString(),
                'ends_on' => $stay->checkOut()->toDateString(),
                'rooms' => $cabins->pluck('id')->all(),
                'reason' => BlockReason::FamTrip->value,
                'notes' => 'Virtuoso agents fam — 4 pax',
            ]);

            app(ReferenceService::class)->ensureAtLeast(ReferenceType::Block, 1);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itineraryRows(): array
    {
        return $this->seedSection('itineraries');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function departureRows(): array
    {
        return $this->seedSection('departures');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seedSection(string $key): array
    {
        $path = base_path('docs/requirements/examples/seed-data.json');

        try {
            /** @var array<string, list<array<string, mixed>>> $seed */
            $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('seed-data.json is not valid JSON.', 0, $exception);
        }

        return $seed[$key] ?? [];
    }
}
