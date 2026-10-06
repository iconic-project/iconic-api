<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Models\Booking;
use App\Models\Delivery;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function aDelivery(): Delivery
{
    $departure = ReservationFixtures::anamaraDeparture('2028-08-06');
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'status' => BookingStatus::Confirmed,
    ]);

    return Delivery::factory()->create([
        'booking_id' => $booking->id,
        'kind' => DeliveryKind::Invoice,
        'status' => DeliveryStatus::Queued,
        'triggered_by' => DeliveryTriggeredBy::User,
    ]);
}

test('a raw delete on deliveries fails', function (): void {
    $delivery = aDelivery();

    expect(fn () => DB::table('deliveries')->where('id', $delivery->id)->delete())
        ->toThrow(QueryException::class);
});

test('a raw update of identifying columns on deliveries fails', function (): void {
    $delivery = aDelivery();

    expect(fn () => DB::table('deliveries')->where('id', $delivery->id)->update(['subject' => 'changed']))
        ->toThrow(QueryException::class);
});

test('status lifecycle updates are allowed', function (): void {
    $delivery = aDelivery();

    $delivery->status = DeliveryStatus::Sent;
    $delivery->sent_at = now();
    $delivery->save();

    expect($delivery->fresh()?->status)->toBe(DeliveryStatus::Sent);
});

test('the model refuses identifying updates and deletes', function (): void {
    $delivery = aDelivery();

    expect(fn () => $delivery->update(['subject' => 'changed']))
        ->toThrow(LogicException::class);
    expect(fn () => $delivery->delete())
        ->toThrow(LogicException::class);
});
