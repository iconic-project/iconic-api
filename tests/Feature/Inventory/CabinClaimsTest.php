<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Enums\ReleaseReason;
use App\Exceptions\CabinUnavailableException;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\Room;
use App\Services\Inventory\ClaimService;
use Database\Seeders\InventorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(InventorySeeder::class);
});

function claimProperty(string $code = 'ANAMARA'): Property
{
    return Property::query()->where('code', $code)->firstOrFail();
}

function futureDeparture(?Property $property = null): Departure
{
    $property ??= claimProperty();

    return Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
}

function cabinOn(Property $property, string $code): Room
{
    return $property->cabins()->where('code', $code)->firstOrFail();
}

function newHolder(string $reference = 'TST-001'): ClaimHolder
{
    return ClaimHolder::query()->create([
        'reference' => $reference,
        'name' => $reference,
    ]);
}

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function inClaimTransaction(callable $callback): mixed
{
    return DB::transaction($callback);
}

test('the database refuses a second active claim on the same cabin', function (): void {
    $departure = futureDeparture();
    $cabin = cabinOn($departure->property, 'S1');
    $holder = newHolder();

    $row = [
        'departure_id' => $departure->id,
        'room_id' => $cabin->id,
        'holder_type' => 'claim_holder',
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Hold->value,
        'hold_type' => HoldType::Web->value,
        'expires_at' => now()->addHour(),
        'released_at' => null,
        'release_reason' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('cabin_claims')->insert($row);

    expect(fn () => DB::table('cabin_claims')->insert([
        ...$row,
        'holder_id' => newHolder('TST-002')->id,
    ]))->toThrow(QueryException::class);

    DB::table('cabin_claims')->where('departure_id', $departure->id)->update([
        'released_at' => now(),
        'release_reason' => ReleaseReason::Released->value,
    ]);

    DB::table('cabin_claims')->insert([
        ...$row,
        'holder_id' => newHolder('TST-003')->id,
    ]);

    expect(CabinClaim::query()->where('departure_id', $departure->id)->whereNull('released_at')->count())->toBe(1);
});

test('claiming several cabins is all or nothing', function (): void {
    $departure = futureDeparture();
    $property = $departure->property;
    $taken = cabinOn($property, 'S2');
    $holder = newHolder();

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([$taken]),
        $holder,
        ClaimKind::Block,
    ));

    try {
        inClaimTransaction(fn () => app(ClaimService::class)->claim(
            $departure,
            collect([cabinOn($property, 'S1'), $taken, cabinOn($property, 'S3')]),
            newHolder('TST-002'),
            ClaimKind::Block,
        ));
        $this->fail('Expected CabinUnavailableException');
    } catch (CabinUnavailableException $exception) {
        expect($exception->unavailable)->toHaveCount(1);
        expect($exception->unavailable[0]['cabin']['code'])->toBe('S2');
        expect($exception->unavailable[0]['held_by']['kind'])->toBe('BLOCK');
        expect($exception->unavailable[0]['held_by']['reference'])->toBe('TST-001');
    }

    expect(CabinClaim::query()->where('departure_id', $departure->id)->whereNull('released_at')->count())->toBe(1);
});

test('claiming over an expired hold releases it then succeeds', function (): void {
    $departure = futureDeparture();
    $cabin = cabinOn($departure->property, 'S4');
    $old = newHolder('HOLD-OLD');

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([$cabin]),
        $old,
        ClaimKind::Hold,
        HoldType::Web,
        now()->addMinutes(20),
    ));

    CabinClaim::query()->where('holder_id', $old->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $fresh = newHolder('HOLD-NEW');

    $claims = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([$cabin]),
        $fresh,
        ClaimKind::Block,
    ));

    expect($claims)->toHaveCount(1);
    expect(CabinClaim::query()->where('holder_id', $old->id)->value('release_reason'))->toBe(ReleaseReason::Expired);
    expect(ChangeHistory::query()->where('event', 'hold.expired')->where('subject_id', $old->id)->count())->toBe(1);
    expect(CabinClaim::query()->whereNull('released_at')->where('holder_id', $fresh->id)->count())->toBe(1);
});

test('hold.expired is attributed to System when a user claims over an expired hold', function (): void {
    $carolina = adminUser(['name' => 'Carolina']);
    $this->actingAs($carolina);

    $departure = futureDeparture();
    $cabin = cabinOn($departure->property, 'S5');
    $old = newHolder('HOLD-EXP');

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([$cabin]),
        $old,
        ClaimKind::Hold,
        HoldType::Web,
        now()->addMinutes(20),
    ));

    CabinClaim::query()->where('holder_id', $old->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([$cabin]),
        newHolder('HOLD-NEW-2'),
        ClaimKind::Block,
    ));

    $entry = ChangeHistory::query()
        ->where('event', 'hold.expired')
        ->where('subject_id', $old->id)
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry?->actor_id)->toBeNull();
    expect($entry?->actor_label)->toBe('System');
});

test('convert moves claims between holders atomically', function (): void {
    $departure = futureDeparture();
    $cabins = $departure->property->cabins()->whereIn('code', ['S1', 'S2'])->orderBy('sort')->get();
    $from = newHolder('FROM-1');
    $to = newHolder('TO-1');

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        $cabins,
        $from,
        ClaimKind::Hold,
        HoldType::Request,
        now()->addHours(2),
    ));

    $converted = inClaimTransaction(fn () => app(ClaimService::class)->convert($from, $to, ClaimKind::Booking));

    expect($converted)->toBe(2);
    expect(CabinClaim::query()->where('holder_id', $from->id)->whereNull('released_at')->count())->toBe(0);
    expect(CabinClaim::query()->where('holder_id', $from->id)->value('release_reason'))->toBe(ReleaseReason::Converted);
    expect(CabinClaim::query()->where('holder_id', $to->id)->whereNull('released_at')->pluck('kind')->unique()->all())
        ->toBe([ClaimKind::Booking]);
});

test('deleting a claim through the model throws', function (): void {
    $departure = futureDeparture();
    $claim = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([cabinOn($departure->property, 'S1')]),
        newHolder(),
        ClaimKind::Block,
    ))->first();

    expect(fn () => $claim?->delete())->toThrow(LogicException::class);
});

test('a raw delete on cabin_claims fails at the database', function (): void {
    $departure = futureDeparture();
    $claim = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $departure,
        collect([cabinOn($departure->property, 'S1')]),
        newHolder(),
        ClaimKind::Block,
    ))->first();

    expect(fn () => DB::table('cabin_claims')->where('id', $claim?->id)->delete())
        ->toThrow(QueryException::class);
});
