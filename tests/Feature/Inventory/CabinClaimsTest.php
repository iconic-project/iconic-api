<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Exceptions\RoomUnavailableException;
use App\Models\ChangeHistory;
use App\Models\InternalBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Inventory\ClaimService;
use App\Support\Stays\StayDates;
use Database\Seeders\InventorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(InventorySeeder::class);
});

function claimProperty(string $code = 'ANAMARA'): Property
{
    return Property::query()->where('code', $code)->firstOrFail();
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

function nightStay(string $checkIn = '2028-04-02', int $nights = 3): StayDates
{
    return StayDates::forNights($checkIn, $nights);
}

test('the database refuses a second active claim on the same room night (nights)', function (): void {
    $room = cabinOn(claimProperty(), 'S1');
    $holder = newHolder();
    $group = (string) Str::uuid();

    $row = [
        'room_id' => $room->id,
        'night' => '2028-04-02',
        'holder_type' => 'claim_holder',
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Hold->value,
        'hold_type' => HoldType::Web->value,
        'expires_at' => now()->addHour(),
        'released_at' => null,
        'release_reason' => null,
        'claim_group' => $group,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('room_night_claims')->insert($row);

    expect(fn () => DB::table('room_night_claims')->insert([
        ...$row,
        'claim_group' => (string) Str::uuid(),
        'holder_id' => newHolder('TST-002')->id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    DB::table('room_night_claims')->where('room_id', $room->id)->update([
        'released_at' => now(),
        'release_reason' => ReleaseReason::Released->value,
    ]);

    DB::table('room_night_claims')->insert([
        ...$row,
        'claim_group' => (string) Str::uuid(),
        'holder_id' => newHolder('TST-003')->id,
    ]);

    expect(RoomNightClaim::query()->where('room_id', $room->id)->whereNull('released_at')->count())->toBe(1);
});

test('claiming several rooms is all or nothing (nights)', function (): void {
    $property = claimProperty();
    $stay = nightStay();
    $taken = cabinOn($property, 'S2');
    $holder = newHolder();

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $stay,
        collect([$taken]),
        $holder,
        ClaimKind::Block,
    ));

    try {
        inClaimTransaction(fn () => app(ClaimService::class)->claim(
            $stay,
            collect([cabinOn($property, 'S1'), $taken, cabinOn($property, 'S3')]),
            newHolder('TST-002'),
            ClaimKind::Block,
        ));
        $this->fail('Expected RoomUnavailableException');
    } catch (RoomUnavailableException $exception) {
        expect($exception->getStatusCode())->toBe(409);
        expect($exception->night)->toBe('2028-04-02');
        expect($exception->roomType)->toBe($taken->roomType->name);
    }

    expect(RoomNightClaim::query()->whereNull('released_at')->count())->toBe($stay->nights());
    expect(RoomNightClaim::query()->where('holder_id', $holder->id)->whereNull('released_at')->pluck('room_id')->unique()->all())
        ->toBe([$taken->id]);
});

test('claiming over an expired hold releases it then succeeds (nights)', function (): void {
    $room = cabinOn(claimProperty(), 'S4');
    $stay = nightStay();
    $old = newHolder('HOLD-OLD');

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $stay,
        collect([$room]),
        $old,
        ClaimKind::Hold,
        HoldType::Web,
        now()->addMinutes(20),
    ));

    RoomNightClaim::query()->where('holder_id', $old->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $fresh = newHolder('HOLD-NEW');

    $claims = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $stay,
        collect([$room]),
        $fresh,
        ClaimKind::Block,
    ));

    expect($claims)->toHaveCount($stay->nights());
    expect($claims->pluck('claim_group')->unique())->toHaveCount(1);
    expect(RoomNightClaim::query()->where('holder_id', $old->id)->value('release_reason'))->toBe(ReleaseReason::Expired);
    expect(ChangeHistory::query()->where('event', 'hold.expired')->where('subject_id', $old->id)->count())->toBe(1);
    expect(RoomNightClaim::query()->whereNull('released_at')->where('holder_id', $fresh->id)->count())->toBe($stay->nights());
});

test('hold.expired is attributed to System when a user claims over an expired hold (nights)', function (): void {
    $carolina = adminUser(['name' => 'Carolina']);
    $this->actingAs($carolina);

    $room = cabinOn(claimProperty(), 'S5');
    $old = newHolder('HOLD-EXP');

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        nightStay(),
        collect([$room]),
        $old,
        ClaimKind::Hold,
        HoldType::Web,
        now()->addMinutes(20),
    ));

    RoomNightClaim::query()->where('holder_id', $old->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        nightStay(),
        collect([$room]),
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

test('convert moves claims between holders atomically (nights)', function (): void {
    $property = claimProperty();
    $rooms = $property->cabins()->whereIn('code', ['S1', 'S2'])->orderBy('sort')->get();
    $stay = nightStay();
    $from = newHolder('FROM-1');
    $to = newHolder('TO-1');

    inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $stay,
        $rooms,
        $from,
        ClaimKind::Hold,
        HoldType::Request,
        now()->addHours(2),
    ));

    $converted = inClaimTransaction(fn () => app(ClaimService::class)->convert($from, $to, ClaimKind::Booking));

    expect($converted)->toBe(2);
    expect(RoomNightClaim::query()->where('holder_id', $from->id)->whereNull('released_at')->count())->toBe(0);
    expect(RoomNightClaim::query()->where('holder_id', $from->id)->value('release_reason'))->toBe(ReleaseReason::Converted);
    expect(RoomNightClaim::query()->where('holder_id', $to->id)->whereNull('released_at')->count())
        ->toBe($stay->nights() * 2);
    expect(RoomNightClaim::query()->where('holder_id', $to->id)->whereNull('released_at')->pluck('kind')->unique()->all())
        ->toBe([ClaimKind::Booking]);
});

test('deleting a claim through the model throws (nights)', function (): void {
    $claim = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        nightStay(),
        collect([cabinOn(claimProperty(), 'S1')]),
        newHolder(),
        ClaimKind::Block,
    ))->first();

    expect(fn () => $claim?->delete())->toThrow(LogicException::class);
});

test('a raw delete on room_night_claims fails at the database (nights)', function (): void {
    $claim = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        nightStay(),
        collect([cabinOn(claimProperty(), 'S1')]),
        newHolder(),
        ClaimKind::Block,
    ))->first();

    expect(fn () => DB::table('room_night_claims')->where('id', $claim?->id)->delete())
        ->toThrow(QueryException::class);
});

test('releasing two nights of a five-night stay leaves three active in the same group (nights)', function (): void {
    $room = cabinOn(claimProperty(), 'S3');
    $stay = nightStay('2028-06-01', 5);
    $holder = newHolder('PART-1');

    $claims = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        $stay,
        collect([$room]),
        $holder,
        ClaimKind::Block,
    ));

    $group = $claims->first()?->claim_group;

    $released = inClaimTransaction(fn () => app(ClaimService::class)->release(
        $holder,
        ReleaseReason::Released,
        null,
        StayDates::forNights('2028-06-01', 2),
    ));

    expect($released)->toBe(2);
    expect(RoomNightClaim::query()->where('holder_id', $holder->id)->whereNull('released_at')->count())->toBe(3);
    expect(RoomNightClaim::query()->where('holder_id', $holder->id)->pluck('claim_group')->unique()->all())->toBe([$group]);
    expect(RoomNightClaim::query()->where('holder_id', $holder->id)->whereNull('released_at')->orderBy('night')->pluck('night')->map(
        fn ($night) => $night->toDateString(),
    )->all())->toBe(['2028-06-03', '2028-06-04', '2028-06-05']);
});

test('claimType locks the type and writes one group of nights (nights)', function (): void {
    $type = RoomType::query()->where('code', 'SUITE')->firstOrFail();
    $stay = nightStay('2028-07-01', 2);
    $holder = newHolder('TYPE-1');

    $claims = inClaimTransaction(fn () => app(ClaimService::class)->claimType(
        $stay,
        $type,
        1,
        $holder,
        ClaimKind::Block,
    ));

    expect($claims)->toHaveCount(2);
    expect($claims->pluck('claim_group')->unique())->toHaveCount(1);
    expect($claims->pluck('room_id')->unique())->toHaveCount(1);
});

test('a stay outside the night rules is refused (nights)', function (): void {
    $room = cabinOn(claimProperty(), 'S6');
    $holder = newHolder('RULE-1');

    expect(fn () => inClaimTransaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2020-01-01', 1),
        collect([$room]),
        $holder,
        ClaimKind::Block,
    )))->toThrow(InvalidArgumentException::class, 'Cannot claim a room on a night in the past.');

    expect(fn () => inClaimTransaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2028-08-01', 31),
        collect([$room]),
        $holder,
        ClaimKind::Block,
    )))->toThrow(InvalidArgumentException::class, 'A stay must be between 1 and 30 nights.');

    expect(fn () => inClaimTransaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2035-01-01', 1),
        collect([$room]),
        $holder,
        ClaimKind::Block,
    )))->toThrow(InvalidArgumentException::class, 'Check-in is outside the booking horizon.');

    expect(fn () => inClaimTransaction(fn () => app(ClaimService::class)->claim(
        nightStay(),
        collect([$room]),
        $holder,
        ClaimKind::Hold,
    )))->toThrow(InvalidArgumentException::class, 'A HOLD claim requires an expiry.');
});

test('an internal block may exceed the night limits (nights)', function (): void {
    $room = cabinOn(claimProperty(), 'S8');
    $block = InternalBlock::query()->create([
        'reference' => 'BLK-LONG',
        'reason' => BlockReason::Courtesy,
    ]);

    $claims = inClaimTransaction(fn () => app(ClaimService::class)->claim(
        StayDates::forNights('2028-09-01', 31),
        collect([$room]),
        $block,
        ClaimKind::Block,
    ));

    expect($claims)->toHaveCount(31);
});
