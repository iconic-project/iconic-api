<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ReleaseReason;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Exceptions\RoomUnavailableException;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Inventory\RoomAllocator;
use App\Support\Stays\StayDates;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Tests\Support\Inventory\ClaimHolder;

function allocatorType(string $name = 'Garden Suite', string $code = 'GSUITE'): RoomType
{
    $property = Property::factory()->create();

    return RoomType::query()->create([
        'property_id' => $property->id,
        'code' => $code,
        'name' => $name,
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 1,
        'waitlist_enabled' => true,
        'sort' => 0,
        'status' => RoomTypeStatus::Active,
    ]);
}

function allocatorRoom(RoomType $type, string $code, int $sort, RoomStatus $status = RoomStatus::Active, ?int $id = null): Room
{
    $attributes = [
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'code' => $code,
        'label' => 'Room '.$code,
        'sort' => $sort,
        'status' => $status,
    ];

    if ($id === null) {
        return Room::query()->create($attributes);
    }

    return Room::unguarded(fn (): Room => Room::query()->create(['id' => $id, ...$attributes]));
}

function allocatorClaim(
    Room $room,
    string $night,
    ClaimKind $kind = ClaimKind::Booking,
    ?CarbonInterface $expiresAt = null,
    ?CarbonInterface $releasedAt = null,
): void {
    $holder = ClaimHolder::query()->create([
        'reference' => (string) Str::uuid(),
        'name' => 'Holder',
    ]);

    RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => $night,
        'holder_type' => $holder->getMorphClass(),
        'holder_id' => $holder->id,
        'kind' => $kind,
        'hold_type' => $kind === ClaimKind::Hold ? HoldType::Web : null,
        'expires_at' => $expiresAt,
        'released_at' => $releasedAt,
        'release_reason' => $releasedAt === null ? null : ReleaseReason::Released,
        'claim_group' => (string) Str::uuid(),
    ]);
}

test('gap filling picks the room busy the night before over a room free around the stay', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 2);
    $freeAround = allocatorRoom($type, '102', 1);
    $busyBefore = allocatorRoom($type, '101', 1);
    allocatorClaim($busyBefore, '2028-06-09');

    $picked = app(RoomAllocator::class)->pick($type, $stay);

    expect($picked)->toHaveCount(1);
    expect($picked->first()?->code)->toBe('101');
    expect($picked->first()?->is($freeAround))->toBeFalse();
});

test('a room that fills a gap beats one side, and one side beats an isolated room', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 2);
    $neither = allocatorRoom($type, 'OPEN', 0);
    $before = allocatorRoom($type, 'BEFORE', 2);
    $after = allocatorRoom($type, 'AFTER', 1);
    $both = allocatorRoom($type, 'BOTH', 9);
    allocatorClaim($before, '2028-06-09');
    allocatorClaim($after, '2028-06-12');
    allocatorClaim($both, '2028-06-09');
    allocatorClaim($both, '2028-06-12');

    $picked = app(RoomAllocator::class)->pick($type, $stay, 4);

    expect($picked->pluck('code')->all())->toBe(['BOTH', 'AFTER', 'BEFORE', 'OPEN']);
    expect($neither->code)->toBe('OPEN');
});

test('the same score and sort picks the lower id even when that row is inserted later', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 1);
    allocatorRoom($type, 'LATER', 4, id: 9102);
    allocatorRoom($type, 'EARLIER', 4, id: 9101);

    expect(app(RoomAllocator::class)->pick($type, $stay)->first()?->code)->toBe('EARLIER');
});

test('the same input returns the same room across 100 runs when insert order is shuffled', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-08-01', 3);
    $order = [5, 1, 4, 2, 3];

    foreach ($order as $sort) {
        allocatorRoom($type, 'R'.$sort, $sort);
    }

    $allocator = app(RoomAllocator::class);
    $first = $allocator->pick($type, $stay)->first()?->id;

    expect($first)->not->toBeNull();

    for ($run = 0; $run < 100; $run++) {
        expect($allocator->pick($type, $stay)->first()?->id)->toBe($first);
    }

    expect(Room::query()->whereKey($first)->value('code'))->toBe('R1');
});

test('a type with a free room every night but no room for the whole stay is unavailable', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-07-01', 2);
    $firstOnly = allocatorRoom($type, 'A', 1);
    $secondOnly = allocatorRoom($type, 'B', 2);
    allocatorClaim($firstOnly, '2028-07-02');
    allocatorClaim($secondOnly, '2028-07-01');

    $allocator = app(RoomAllocator::class);

    try {
        $allocator->pick($type, $stay);
        $this->fail('Expected RoomUnavailableException');
    } catch (RoomUnavailableException $exception) {
        expect($exception->roomType)->toBe('Garden Suite');
        expect($exception->night)->toBe('2028-07-02');
        expect($exception->getMessage())->toBe('Garden Suite is unavailable on 2028-07-02.');
        expect($exception->getStatusCode())->toBe(409);
        expect($exception->render()->getStatusCode())->toBe(409);
        expect($exception->render()->getData(true))->toBe([
            'message' => 'Garden Suite is unavailable on 2028-07-02.',
        ]);
    }

    expect($allocator->explain($type, $stay))->toBe([
        ['night' => '2028-07-01', 'free' => 1],
        ['night' => '2028-07-02', 'free' => 1],
    ]);
});

test('the exception names the first night that drops the stay below the requested count', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-09-01', 3);
    $room = allocatorRoom($type, 'ONLY', 1);
    allocatorClaim($room, '2028-09-03');

    expect(fn () => app(RoomAllocator::class)->pick($type, $stay))
        ->toThrow(RoomUnavailableException::class, 'Garden Suite is unavailable on 2028-09-03.');

    expect(app(RoomAllocator::class)->explain($type, $stay))->toBe([
        ['night' => '2028-09-01', 'free' => 1],
        ['night' => '2028-09-02', 'free' => 1],
        ['night' => '2028-09-03', 'free' => 0],
    ]);
});

test('inactive rooms are never picked and do not count as free', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 1);
    allocatorRoom($type, 'CLOSED', 0, RoomStatus::Inactive);
    $open = allocatorRoom($type, 'OPEN', 5);

    expect(app(RoomAllocator::class)->pick($type, $stay)->first()?->is($open))->toBeTrue();
    expect(app(RoomAllocator::class)->explain($type, $stay))->toBe([
        ['night' => '2028-06-10', 'free' => 1],
    ]);

    $empty = allocatorType('Closed type', 'CLOSED');
    allocatorRoom($empty, 'X', 0, RoomStatus::Inactive);

    expect(fn () => app(RoomAllocator::class)->pick($empty, $stay))
        ->toThrow(RoomUnavailableException::class, 'Closed type is unavailable on 2028-06-10.');
    expect(app(RoomAllocator::class)->explain($empty, $stay))->toBe([
        ['night' => '2028-06-10', 'free' => 0],
    ]);
});

test('expired holds and released claims leave the room free', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 2);
    $expired = allocatorRoom($type, 'EXP', 2);
    $released = allocatorRoom($type, 'REL', 2);
    $live = allocatorRoom($type, 'LIVE', 3);
    allocatorClaim($expired, '2028-06-10', ClaimKind::Hold, now()->subMinute());
    allocatorClaim($expired, '2028-06-11', ClaimKind::Hold, now()->subMinute());
    allocatorClaim($expired, '2028-06-09', ClaimKind::Hold, now()->subMinute());
    allocatorClaim($released, '2028-06-10', releasedAt: now());
    allocatorClaim($released, '2028-06-11', releasedAt: now());
    allocatorClaim($live, '2028-06-10', ClaimKind::Hold, now()->addHour());

    $picked = app(RoomAllocator::class)->pick($type, $stay, 2);

    expect($picked->pluck('code')->all())->toBe(['EXP', 'REL']);
    expect(app(RoomAllocator::class)->explain($type, $stay))->toBe([
        ['night' => '2028-06-10', 'free' => 2],
        ['night' => '2028-06-11', 'free' => 3],
    ]);
});

test('excluded rooms are skipped and the next room is picked', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 1);
    $best = allocatorRoom($type, 'BEST', 1);
    $next = allocatorRoom($type, 'NEXT', 2);

    $picked = app(RoomAllocator::class)->pick($type, $stay, excludeRoomIds: [$best->id]);

    expect($picked)->toHaveCount(1);
    expect($picked->first()?->is($next))->toBeTrue();

    expect(fn () => app(RoomAllocator::class)->pick($type, $stay, excludeRoomIds: [$best->id, $next->id]))
        ->toThrow(RoomUnavailableException::class, 'Garden Suite is unavailable on 2028-06-10.');
});

test('two rooms are returned in score order', function (): void {
    $type = allocatorType();
    $stay = StayDates::forNights('2028-06-10', 1);
    allocatorRoom($type, 'SECOND', 2);
    $first = allocatorRoom($type, 'FIRST', 1);
    allocatorClaim($first, '2028-06-09');

    expect(app(RoomAllocator::class)->pick($type, $stay, 2)->pluck('code')->all())->toBe(['FIRST', 'SECOND']);
});

test('rooms of another type are ignored', function (): void {
    $type = allocatorType();
    $other = allocatorType('Other', 'OTHER');
    $stay = StayDates::forNights('2028-06-10', 1);
    allocatorRoom($other, 'THEIRS', 0);
    $ours = allocatorRoom($type, 'OURS', 9);

    expect(app(RoomAllocator::class)->pick($type, $stay)->first()?->is($ours))->toBeTrue();
});
