<?php

declare(strict_types=1);

use App\Actions\Contacts\MergeContacts;
use App\Models\Contact;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function onContactMergeConnection(string $name, callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($name);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function contactMergeMysqlError(QueryException $e): int
{
    return (int) ($e->errorInfo[1] ?? 0);
}

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);

    config(['database.connections.mysql_lock' => config('database.connections.mysql')]);
    DB::purge('mysql_lock');
    DB::connection('mysql_lock')->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

test('two merges sharing a contact wait 1205 never 1213', function (): void {
    $actor = managerUser();
    Auth::login($actor);

    $shared = Contact::factory()->create(['email' => 'shared@iconic.test']);
    $left = Contact::factory()->create(['email' => 'left@iconic.test']);
    $right = Contact::factory()->create(['email' => 'right@iconic.test']);
    $observed = 'none';

    onContactMergeConnection('mysql', function () use ($shared, $left, $actor): void {
        DB::beginTransaction();
        app(MergeContacts::class)->handle($shared, $left, 'first', $actor);
    });

    onContactMergeConnection('mysql_lock', function () use ($shared, $right, $actor, &$observed): void {
        Auth::login($actor);

        try {
            app(MergeContacts::class)->handle($shared, $right, 'second', $actor);
            expect(false)->toBeTrue('the second merge should wait on the contact lock (1205)');
        } catch (QueryException $e) {
            $code = contactMergeMysqlError($e);
            expect($code)->toBe(1205);
            expect($code)->not->toBe(1213);
            $observed = (string) $code;
        }
    });

    onContactMergeConnection('mysql', function (): void {
        DB::commit();
    });

    expect($observed)->toBe('1205');
});
