<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Enums\ReleaseReason;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Services\Inventory\ClaimService;
use App\Support\Inventory\BackfillRoomNightClaims;
use Database\Seeders\InventorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(InventorySeeder::class);
});

/**
 * @return array<string, mixed>
 */
function roomNightRow(int $roomId, string $night, int $holderId, ?string $releasedAt = null): array
{
    return [
        'room_id' => $roomId,
        'night' => $night,
        'holder_type' => 'claim_holder',
        'holder_id' => $holderId,
        'kind' => $releasedAt === null ? ClaimKind::Hold->value : ClaimKind::Block->value,
        'hold_type' => $releasedAt === null ? HoldType::Web->value : null,
        'expires_at' => $releasedAt === null ? now()->addHour() : null,
        'released_at' => $releasedAt,
        'release_reason' => $releasedAt === null ? null : ReleaseReason::Released->value,
        'claim_group' => (string) Str::uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('a yacht claim writes one night row per stay night and cabin_claims refuses inserts', function (): void {
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->firstOrFail()->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
    $holdRoom = $departure->property->cabins()->where('code', 'S1')->firstOrFail();
    $releasedRoom = $departure->property->cabins()->where('code', 'S2')->firstOrFail();
    $holdHolder = ClaimHolder::query()->create(['reference' => 'RNC-HOLD', 'name' => 'Hold']);
    $releasedHolder = ClaimHolder::query()->create(['reference' => 'RNC-REL', 'name' => 'Released']);

    DB::transaction(fn () => app(ClaimService::class)->claim($departure->stayDates(),
        collect([$holdRoom]),
        $holdHolder,
        ClaimKind::Hold,
        HoldType::Web,
        now()->addHour(),
    ));

    DB::transaction(function () use ($departure, $releasedRoom, $releasedHolder): void {
        app(ClaimService::class)->claim($departure->stayDates(),
            collect([$releasedRoom]),
            $releasedHolder,
            ClaimKind::Block,
        );
        app(ClaimService::class)->release($releasedHolder, ReleaseReason::Cancelled);
    });

    $stay = $departure->stayDates();
    $held = RoomNightClaim::query()->where('holder_id', $holdHolder->id)->orderBy('night')->get();
    $released = RoomNightClaim::query()->where('holder_id', $releasedHolder->id)->orderBy('night')->get();

    expect($held)->toHaveCount($stay->nights());
    expect($held->pluck('claim_group')->unique())->toHaveCount(1);
    expect($held->every(fn (RoomNightClaim $row): bool => $row->released_at === null
        && $row->active_key === $row->room_id.'-'.$row->night->toDateString()))->toBeTrue();
    expect($released)->toHaveCount($stay->nights());
    expect($released->every(fn (RoomNightClaim $row): bool => $row->released_at !== null
        && $row->active_key === null
        && $row->release_reason === ReleaseReason::Cancelled))->toBeTrue();
    expect(app(BackfillRoomNightClaims::class)->run())->toBe(0);

    expect(fn () => DB::table('cabin_claims')->insert([
        'departure_id' => $departure->id,
        'room_id' => $holdRoom->id,
        'holder_type' => 'claim_holder',
        'holder_id' => $holdHolder->id,
        'kind' => ClaimKind::Block->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a second active claim on the same room night is refused', function (): void {
    $room = Room::query()->firstOrFail();
    $holder = ClaimHolder::query()->create(['reference' => 'RNC-1', 'name' => 'One']);

    DB::table('room_night_claims')->insert(roomNightRow($room->id, '2028-04-02', $holder->id));

    expect(fn () => DB::table('room_night_claims')->insert(roomNightRow(
        $room->id,
        '2028-04-02',
        ClaimHolder::query()->create(['reference' => 'RNC-2', 'name' => 'Two'])->id,
    )))->toThrow(UniqueConstraintViolationException::class);
});

test('a released claim and a new active claim on the same room night coexist', function (): void {
    $room = Room::query()->firstOrFail();
    $released = ClaimHolder::query()->create(['reference' => 'RNC-OLD', 'name' => 'Old']);
    $active = ClaimHolder::query()->create(['reference' => 'RNC-NEW', 'name' => 'New']);

    DB::table('room_night_claims')->insert(roomNightRow($room->id, '2028-04-03', $released->id, now()->toDateTimeString()));
    DB::table('room_night_claims')->insert(roomNightRow($room->id, '2028-04-03', $active->id));

    expect(RoomNightClaim::query()->where('room_id', $room->id)->whereDate('night', '2028-04-03')->count())->toBe(2);
    expect(RoomNightClaim::query()->where('room_id', $room->id)->whereDate('night', '2028-04-03')->whereNull('released_at')->count())->toBe(1);

    expect(fn () => DB::table('room_night_claims')->insert(roomNightRow(
        $room->id,
        '2028-04-03',
        ClaimHolder::query()->create(['reference' => 'RNC-THIRD', 'name' => 'Third'])->id,
    )))->toThrow(UniqueConstraintViolationException::class);
});

test('room night claims cannot be deleted', function (): void {
    $room = Room::query()->firstOrFail();
    $holder = ClaimHolder::query()->create(['reference' => 'RNC-DEL', 'name' => 'Delete']);

    $claim = RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => '2028-05-01',
        'holder_type' => 'claim_holder',
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Block,
        'claim_group' => (string) Str::uuid(),
    ]);

    expect(fn () => $claim->delete())->toThrow(LogicException::class);
    expect(fn () => DB::table('room_night_claims')->where('id', $claim->id)->delete())
        ->toThrow(QueryException::class);
});

test('only release columns on a room night claim can change', function (): void {
    $room = Room::query()->firstOrFail();
    $holder = ClaimHolder::query()->create(['reference' => 'RNC-IMM', 'name' => 'Immutable']);

    $claim = RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => '2028-05-02',
        'holder_type' => 'claim_holder',
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Hold,
        'hold_type' => HoldType::Web,
        'expires_at' => now()->addHour(),
        'claim_group' => (string) Str::uuid(),
    ]);

    expect(function () use ($claim): void {
        $claim->room_id = Room::query()->where('id', '!=', $claim->room_id)->value('id');
        $claim->save();
    })->toThrow(LogicException::class);

    $claim->refresh();

    expect(fn () => $claim->update(['kind' => ClaimKind::Booking]))->toThrow(LogicException::class);

    $claim->refresh();
    $claim->released_at = now();
    $claim->release_reason = ReleaseReason::Expired;
    $claim->save();

    $claim->refresh();

    expect($claim->release_reason)->toBe(ReleaseReason::Expired);
    expect($claim->released_at)->not->toBeNull();
    expect($claim->active_key)->toBeNull();
    expect($claim->kind)->toBe(ClaimKind::Hold);
});
