<?php

declare(strict_types=1);

use App\Enums\ReferenceType;
use App\Services\References\ReferenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['database.connections.mysql_lock' => config('database.connections.mysql')]);
    DB::purge('mysql_lock');
    DB::connection('mysql_lock')->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onDefaultConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function mysqlErrorCode(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

test('an existing scope waits with 1205 then yields the next number', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2026-06-15', 'Pacific/Galapagos');

    onDefaultConnection('mysql', function () use ($service, $at): void {
        DB::beginTransaction();
        expect($service->next(ReferenceType::Booking, $at))->toBe('HTL-2026-0001');
        DB::commit();
    });

    $first = onDefaultConnection('mysql', function () use ($service, $at): string {
        DB::beginTransaction();

        return $service->next(ReferenceType::Booking, $at);
    });

    expect($first)->toBe('HTL-2026-0002');

    onDefaultConnection('mysql_lock', function () use ($service, $at): void {
        DB::beginTransaction();

        try {
            $service->next(ReferenceType::Booking, $at);
            expect(false)->toBeTrue('the second draw should wait, not succeed');
        } catch (QueryException $e) {
            expect(mysqlErrorCode($e))->toBe(1205);
            expect(mysqlErrorCode($e))->not->toBe(1213);
            DB::rollBack();
        }
    });

    onDefaultConnection('mysql', function (): void {
        DB::commit();
    });

    $second = onDefaultConnection('mysql_lock', function () use ($service, $at): string {
        DB::beginTransaction();
        $ref = $service->next(ReferenceType::Booking, $at);
        DB::commit();

        return $ref;
    });

    expect($second)->toBe('HTL-2026-0003');
});

test('a new scope waits with 1205 then yields the next number', function (): void {
    $service = app(ReferenceService::class);
    $at = CarbonImmutable::parse('2031-06-15', 'Pacific/Galapagos');

    $first = onDefaultConnection('mysql', function () use ($service, $at): string {
        DB::beginTransaction();

        return $service->next(ReferenceType::Booking, $at);
    });

    expect($first)->toBe('HTL-2031-0001');

    onDefaultConnection('mysql_lock', function () use ($service, $at): void {
        DB::beginTransaction();

        try {
            $service->next(ReferenceType::Booking, $at);
            expect(false)->toBeTrue('the second draw should wait, not succeed');
        } catch (QueryException $e) {
            expect(mysqlErrorCode($e))->toBe(1205);
            expect(mysqlErrorCode($e))->not->toBe(1213);
            DB::rollBack();
        }
    });

    onDefaultConnection('mysql', function (): void {
        DB::commit();
    });

    $second = onDefaultConnection('mysql_lock', function () use ($service, $at): string {
        DB::beginTransaction();
        $ref = $service->next(ReferenceType::Booking, $at);
        DB::commit();

        return $ref;
    });

    expect($second)->toBe('HTL-2031-0002');
});
