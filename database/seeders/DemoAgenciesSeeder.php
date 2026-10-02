<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Contacts\ResolveContact;
use App\Enums\AgencyStatus;
use App\Enums\AgencyUserStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\ContactType;
use App\Enums\ReferenceType;
use App\Models\Agency;
use App\Models\AgencyUser;
use App\Models\Booking;
use App\Models\Cabin;
use App\Models\Departure;
use App\Models\Property;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Services\Pricing\CabinPricer;
use App\Services\Pricing\NoRate;
use App\Services\Pricing\QuoteInput;
use App\Services\References\ReferenceService;
use App\Support\Bookings\ChannelSeedMap;
use App\Support\Bookings\SoldOn;
use App\Support\History\History;
use App\Support\Inventory\DepartureLocks;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class DemoAgenciesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $seed = $this->seedFile();
        $agencies = $seed['agencies'] ?? [];

        DB::transaction(function () use ($agencies): void {
            foreach ($agencies as $row) {
                $this->seedAgency($row);
            }

            $this->seedAcceptedPortalUser();
            $this->attachApprovedBooking();
            $this->seedBlockedBooking();
            $this->seedUnmatchedAgency();

            app(ReferenceService::class)->ensureAtLeast(ReferenceType::Agency, 4);
            app(ReferenceService::class)->ensureAtLeast(ReferenceType::Booking, 21, 2026);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function seedAgency(array $row): void
    {
        $reference = (string) $row['id'];
        $status = AgencyStatus::from((string) $row['status']);
        $requested = Carbon::parse((string) $row['reqAt'], 'Pacific/Galapagos')->utc();
        $decidedBy = $status === AgencyStatus::Approved ? $this->carolina()?->id : null;

        $agency = Agency::query()->firstOrCreate(
            ['reference' => $reference],
            [
                'name' => (string) $row['name'],
                'contact' => (string) $row['contact'],
                'email' => (string) $row['email'],
                'country' => (string) $row['country'],
                'network' => (string) $row['network'],
                'commission_pct' => (int) $row['comm'],
                'payment_terms' => (string) $row['terms'],
                'status' => $status,
                'requested_at' => $requested,
                'decided_at' => $status === AgencyStatus::Approved ? $requested->copy()->addDays(2) : null,
                'decided_by' => $decidedBy,
            ],
        );

        $users = is_array($row['users'] ?? null) ? $row['users'] : [];

        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }

            $email = (string) ($user['e'] ?? '');

            if ($email === '') {
                continue;
            }

            AgencyUser::query()->firstOrCreate(
                ['agency_id' => $agency->id, 'email' => $email],
                [
                    'name' => (string) ($user['n'] ?? $agency->contact),
                    'status' => $this->userStatus((string) ($user['st'] ?? '')),
                ],
            );
        }

        app(ResolveContact::class)->handle([
            'name' => $agency->contact !== '' ? $agency->contact : $agency->name,
            'email' => $agency->email,
            'country' => $agency->country,
            'type' => ContactType::TravelAgent,
        ]);
    }

    private function userStatus(string $status): AgencyUserStatus
    {
        return match (strtolower($status)) {
            'active' => AgencyUserStatus::Active,
            'invited', 'invite sent', 'invite on portal launch' => AgencyUserStatus::InviteOnPortalLaunch,
            'disabled' => AgencyUserStatus::Disabled,
            'invite on approval', 'pending' => AgencyUserStatus::InviteOnApproval,
            default => AgencyUserStatus::InviteOnApproval,
        };
    }

    /**
     * One accepted portal login for the e2e scenarios. Not a staff user.
     */
    private function seedAcceptedPortalUser(): void
    {
        $agency = Agency::query()->where('reference', 'AG-001')->first();

        if (! $agency instanceof Agency) {
            return;
        }

        $user = AgencyUser::query()->firstOrCreate(
            ['agency_id' => $agency->id, 'email' => 'ada@portal.test'],
            [
                'name' => 'Ada Agent',
                'status' => AgencyUserStatus::Active,
            ],
        );

        if ($user->password !== null && $user->accepted_at !== null && $user->status === AgencyUserStatus::Active) {
            return;
        }

        $user->forceFill([
            'name' => 'Ada Agent',
            'status' => AgencyUserStatus::Active,
            'password' => 'password',
            'accepted_at' => $user->accepted_at ?? now(),
        ])->save();
    }

    private function attachApprovedBooking(): void
    {
        $agency = Agency::query()->where('reference', 'AG-001')->first();
        $booking = Booking::query()->where('reference', 'ANK-2026-0007')->first();

        if (! $agency instanceof Agency || ! $booking instanceof Booking) {
            return;
        }

        if ($booking->agency_id === $agency->id) {
            return;
        }

        $booking->agency_id = $agency->id;
        $booking->commission_pct = $agency->commission_pct;
        $booking->commission_approved = true;
        $booking->save();
    }

    private function seedBlockedBooking(): void
    {
        if (Booking::query()->where('reference', 'ANK-2026-0021')->exists()) {
            return;
        }

        $agency = Agency::query()->where('reference', 'AG-002')->first();

        if (! $agency instanceof Agency) {
            throw new RuntimeException('AG-002 must exist before seeding ANK-2026-0021.');
        }

        $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
        $departure = Departure::query()
            ->where('property_id', $property->id)
            ->whereDate('date', '2027-11-14')
            ->with('property.cabins')
            ->first();

        if (! $departure instanceof Departure) {
            throw new RuntimeException('ANAMARA 2027-11-14 is not seeded.');
        }

        DepartureLocks::lock($departure->id);

        $cabin = $departure->property->cabins->firstWhere('code', 'S1');

        if (! $cabin instanceof Cabin) {
            throw new RuntimeException('Suite 01 is missing on ANAMARA.');
        }

        $channels = ChannelSeedMap::fromPrototype('AGENCY');
        $input = new QuoteInput(
            year: 2027,
            type: BookingType::Cabin->quoteType(),
            category: $cabin->category,
            adults: 2,
            children: 0,
            festive: $departure->festive,
        );
        $priced = app(CabinPricer::class)->quote(app(CurrentConfig::class)->rates(), $input);

        if ($priced instanceof NoRate) {
            throw new RuntimeException($priced->reason.' for ANK-2026-0021');
        }

        $contact = app(ResolveContact::class)->handle([
            'name' => 'Meridian Voyages hold',
        ]);
        $owner = $this->carolina() ?? User::query()->firstOrFail();
        $terms = app(CurrentConfig::class)->rates()->terms;
        $cap = app(CurrentConfig::class)->businessRules()->commission->capPct;

        $booking = Booking::query()->create([
            'reference' => 'ANK-2026-0021',
            'type' => BookingType::Cabin,
            'departure_id' => $departure->id,
            'cabin_id' => $cabin->id,
            'contact_id' => $contact->id,
            'owner_id' => $owner->id,
            'agency_id' => $agency->id,
            'commission_pct' => $agency->commission_pct,
            'commission_approved' => false,
            'status' => BookingStatus::OnHoldAgency,
            'main_channel' => $channels['main'],
            'channel_of_origin' => $channels['origin'],
            'adults' => 2,
            'children' => 0,
            'back_to_back' => false,
            'rates_version_id' => app(CurrentConfig::class)->version(ConfigKind::Rates)->id,
            'price_lines' => $priced->toArray()['lines'],
            'total' => $priced->total,
            'deposit_pct' => $priced->depositPct,
            'balance_days' => $terms->cabinBalanceDays,
            'online_deposit' => false,
            'sold_on' => SoldOn::today(),
        ]);

        app(ClaimService::class)->claim($departure, collect([$cabin]), $booking, ClaimKind::Booking);

        History::record($booking, 'booking.created', after: [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'total' => $booking->total,
            'what' => 'Reservation created in RMS — '.$booking->cabinLabel().' · seeded ON_HOLD_AGENCY',
        ]);
        History::record($booking, 'booking.commission_held', after: [
            'what' => 'HELD — commission '.$agency->commission_pct.' % above '.$cap.' % cap · Director alert sent (FIN-005)',
            'commission_pct' => $agency->commission_pct,
            'cap_pct' => $cap,
        ], system: true);
    }

    /**
     * B2B-05. Stays off the ResolveContact loop and does not dispatch AgencyApproved.
     * Email match is the only CRM link. A row in seed-data.json would create a contact.
     */
    private function seedUnmatchedAgency(): void
    {
        Agency::query()->firstOrCreate(
            ['reference' => 'AG-004'],
            [
                'name' => 'Unmatched B2B',
                'contact' => 'Unmatched B2B',
                'email' => 'nobody-b2b@iconic.test',
                'country' => null,
                'network' => null,
                'commission_pct' => 10,
                'payment_terms' => '30 days post-cruise · wire',
                'status' => AgencyStatus::Pending,
                'requested_at' => now(),
                'decided_at' => null,
                'decided_by' => null,
            ],
        );
    }

    private function carolina(): ?User
    {
        return User::query()
            ->get()
            ->first(fn (User $user): bool => str_starts_with($user->name, 'Carolina'));
    }

    /**
     * @return array{agencies: list<array<string, mixed>>}
     */
    private function seedFile(): array
    {
        $path = base_path('docs/requirements/examples/seed-data.json');

        try {
            /** @var array{agencies: list<array<string, mixed>>} $seed */
            $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('seed-data.json is not valid JSON.', 0, $exception);
        }

        return $seed;
    }
}
