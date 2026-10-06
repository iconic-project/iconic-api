<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Payment;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a raw delete on payments fails', function (): void {
    $payment = Payment::factory()->create([
        'booking_id' => Booking::factory()->create([
        ])->id,
    ]);

    expect(fn () => DB::table('payments')->where('id', $payment->id)->delete())
        ->toThrow(QueryException::class);
});

test('a raw update of amount on payments fails', function (): void {
    $payment = Payment::factory()->create([
        'booking_id' => Booking::factory()->create([
        ])->id,
        'amount' => 2660,
    ]);

    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount' => 1]))
        ->toThrow(QueryException::class);
});

test('a raw update of status on payments is allowed', function (): void {
    $payment = Payment::factory()->create([
        'booking_id' => Booking::factory()->create([
        ])->id,
    ]);

    DB::table('payments')->where('id', $payment->id)->update([
        'status' => 'REFUNDED',
        'gateway_id' => 'bank-ref',
    ]);

    $payment->refresh();

    expect($payment->status->value)->toBe('REFUNDED');
    expect($payment->gateway_id)->toBe('bank-ref');
});
