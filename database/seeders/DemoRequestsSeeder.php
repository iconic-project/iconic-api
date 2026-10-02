<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Contacts\ResolveContact;
use App\Enums\CabinCategory;
use App\Enums\ChannelOfOrigin;
use App\Enums\MainChannel;
use App\Enums\ReferenceType;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\Yacht;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Request timestamps are relative to now (SLA stays live). Request references
 * are pinned to 2026 so they stay ANK-R-2026-0041/0042 after 1 Jan 2027.
 * Combined with the fixed 2027 departures, the demo is valid until Nov 2027.
 */
final class DemoRequestsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $seed = $this->seedFile();
        $departures = $this->anamaraByDateIndex($seed['departures'] ?? []);
        $channels = ChannelSeedMap::fromPrototype('WEB_DIRECT');
        $referenceAt = CarbonImmutable::parse('2026-07-02', BusinessTime::zone());

        $rows = [
            [
                'request_reference' => 'ANK-R-2026-0041',
                'hours_ago' => 5,
                'owner' => 'Lucía',
                'dep' => 2,
                'cabin' => 'S4',
                'adults' => 2,
                'children' => 0,
                'preferred_channel' => 'WHATSAPP',
                'travel_advisor' => false,
                'notes' => 'Anniversary on board',
                'guest' => 'E. Harmon',
            ],
            [
                'request_reference' => 'ANK-R-2026-0042',
                'hours_ago' => 50,
                'owner' => 'Mateo',
                'dep' => 3,
                'cabin' => 'S5',
                'adults' => 2,
                'children' => 1,
                'preferred_channel' => 'EMAIL',
                'travel_advisor' => true,
                'notes' => 'Travel advisor booking. Client prefers a fore cabin if possible.',
                'guest' => 'L. Moreau',
            ],
        ];

        $missing = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! Booking::query()
                ->where('request_reference', $row['request_reference'])
                ->exists(),
        ));

        if ($missing !== []) {
            DB::transaction(fn () => app(ReferenceService::class)->ensureAtLeast(ReferenceType::Request, 40, 2026));

            foreach ($missing as $row) {
                $this->createRequest($row, $departures, $channels, $referenceAt);
            }
        }

        $this->seedWaitlist($departures);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, Departure>  $departures
     * @param  array{main: MainChannel, origin: ChannelOfOrigin}  $channels
     */
    private function createRequest(
        array $row,
        array $departures,
        array $channels,
        CarbonImmutable $referenceAt,
    ): void {
        $previous = Carbon::getTestNow();

        try {
            Carbon::setTestNow(now()->subHours((int) $row['hours_ago']));

            app(CreateBookingRequest::class)->handle([
                'departure_id' => $this->departureFor((int) $row['dep'], $departures)->id,
                'type' => 'CABIN',
                'back_to_back' => false,
                'cabins' => [[
                    'cabin_code' => $row['cabin'],
                    'adults' => $row['adults'],
                    'children' => $row['children'],
                ]],
                'client' => ['name' => $row['guest']],
                'main_channel' => $channels['main']->value,
                'channel_of_origin' => $channels['origin']->value,
                'preferred_channel' => $row['preferred_channel'],
                'travel_advisor' => $row['travel_advisor'],
                'notes' => $row['notes'],
            ], $this->owner((string) $row['owner']), $referenceAt);
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * @param  array<int, Departure>  $departures
     */
    private function seedWaitlist(array $departures): void
    {
        $departure = $this->departureFor(6, $departures);

        $this->waitlistRow(
            $departure,
            CabinCategory::Suite,
            'Anna Whitfield',
            'whitfield.anna@iconic.test',
            '2026-07-02 12:00:00',
        );
        $this->waitlistRow(
            $departure,
            CabinCategory::Owner,
            'K. Osei',
            'k.osei@iconic.test',
            '2026-07-08 12:00:00',
        );
    }

    private function waitlistRow(
        Departure $departure,
        CabinCategory $category,
        string $name,
        string $email,
        string $since,
    ): void {
        $contact = app(ResolveContact::class)->handle([
            'name' => $name,
            'email' => $email,
        ]);

        WaitlistEntry::query()->firstOrCreate(
            [
                'departure_id' => $departure->id,
                'cabin_category' => $category,
                'contact_id' => $contact->id,
            ],
            [
                'adults' => 2,
                'children' => 0,
                'created_at' => CarbonImmutable::parse($since, BusinessTime::zone())->utc(),
            ],
        );
    }

    /**
     * @param  array<int, Departure>  $departures
     */
    private function departureFor(int $di, array $departures): Departure
    {
        $departure = $departures[$di] ?? null;

        if (! $departure instanceof Departure) {
            throw new RuntimeException('No ANAMARA departure for date index '.$di.'.');
        }

        return $departure;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, Departure>
     */
    private function anamaraByDateIndex(array $rows): array
    {
        $yacht = Yacht::query()->where('code', 'ANAMARA')->firstOrFail();
        $mapped = [];

        foreach ($rows as $row) {
            if (($row['yacht'] ?? '') !== 'ANAMARA') {
                continue;
            }

            $departure = Departure::query()
                ->where('yacht_id', $yacht->id)
                ->whereDate('date', (string) $row['date'])
                ->with('yacht.cabins')
                ->first();

            if (! $departure instanceof Departure) {
                throw new RuntimeException('ANAMARA departure '.$row['date'].' is not seeded.');
            }

            $mapped[(int) $row['di']] = $departure;
        }

        return $mapped;
    }

    private function owner(string $firstName): User
    {
        $user = User::query()
            ->get()
            ->first(fn (User $candidate): bool => str_starts_with($candidate->name, $firstName));

        if (! $user instanceof User) {
            throw new RuntimeException('No demo user named '.$firstName.'.');
        }

        return $user;
    }

    /**
     * @return array{departures: list<array<string, mixed>>, bookings: list<array<string, mixed>>}
     */
    private function seedFile(): array
    {
        $path = base_path('docs/requirements/examples/seed-data.json');

        try {
            /** @var array{departures: list<array<string, mixed>>, bookings: list<array<string, mixed>>} $seed */
            $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('seed-data.json is not valid JSON.', 0, $exception);
        }

        return $seed;
    }
}
