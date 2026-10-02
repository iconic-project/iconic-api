<?php

declare(strict_types=1);

use App\Actions\Contacts\ResolveContact;
use App\Enums\ContactType;
use App\Models\ChangeHistory;
use App\Models\Contact;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('laravel mysql does not set CLIENT_FOUND_ROWS so created is affected === 1', function (): void {
    $options = config('database.connections.mysql.options', []);
    expect($options)->not->toHaveKey(PDO::MYSQL_ATTR_FOUND_ROWS);
    expect($options)->not->toHaveKey(Mysql::ATTR_FOUND_ROWS);

    $email = 'found-rows@iconic.test';
    $now = now()->format('Y-m-d H:i:s');

    $created = (int) DB::affectingStatement(
        'insert into contacts (`name`, `email`, `phone`, `country`, `preferred_channel`, `created_at`, `updated_at`)
         values (?, ?, null, null, ?, ?, ?)
         on duplicate key update `id` = `id`',
        ['First', $email, 'EMAIL', $now, $now],
    );
    $existing = (int) DB::affectingStatement(
        'insert into contacts (`name`, `email`, `phone`, `country`, `preferred_channel`, `created_at`, `updated_at`)
         values (?, ?, null, null, ?, ?, ?)
         on duplicate key update `id` = `id`',
        ['Second', $email, 'EMAIL', $now, $now],
    );

    expect($created)->toBe(1);
    expect($existing)->not->toBe(1);
    expect(ResolveContact::createdFromInsertAffected($created))->toBeTrue();
    expect(ResolveContact::createdFromInsertAffected($existing))->toBeFalse();
});

test('email match is case-insensitive and never overwrites filled fields', function (): void {
    $this->actingAs(managerUser());

    $first = app(ResolveContact::class)->handle([
        'name' => 'Daniel Harrison',
        'email' => 'D.Harrison@Example.TEST',
        'phone' => '+1 555 0100',
    ]);

    $second = app(ResolveContact::class)->handle([
        'name' => 'Someone Else',
        'email' => 'd.harrison@example.test',
        'phone' => '+1 555 9999',
        'country' => 'US',
    ]);

    expect($second->id)->toBe($first->id);
    expect(Contact::query()->where('email', 'd.harrison@example.test')->count())->toBe(1);
    expect($second->name)->toBe('Daniel Harrison');
    expect($second->phone)->toBe('+1 555 0100');
    expect($second->country)->toBe('US');

    expect(ChangeHistory::query()->where('event', 'contact.created')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'contact.updated')->count())->toBe(1);

    $updated = ChangeHistory::query()->where('event', 'contact.updated')->firstOrFail();
    expect($updated->before)->toBe(['country' => null]);
    expect($updated->after)->toBe(['country' => 'US']);
});

test('a second upsert of the same new email does not 500', function (): void {
    $this->actingAs(managerUser());

    $a = app(ResolveContact::class)->handle([
        'name' => 'One',
        'email' => 'same@iconic.test',
    ]);
    $b = app(ResolveContact::class)->handle([
        'name' => 'Two',
        'email' => 'SAME@iconic.test',
    ]);

    expect($b->id)->toBe($a->id);
    expect(Contact::query()->where('email', 'same@iconic.test')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'contact.updated')->count())->toBe(0);
});

test('resolve contact applies type on create and never downgrades', function (): void {
    $this->actingAs(managerUser());

    $passenger = app(ResolveContact::class)->handle([
        'name' => 'Passenger',
        'email' => 'type@iconic.test',
    ]);
    expect($passenger->type)->toBe(ContactType::DirectPassenger);
    expect($passenger->language)->toBe('en');

    $upgraded = app(ResolveContact::class)->handle([
        'name' => 'Passenger',
        'email' => 'type@iconic.test',
        'type' => ContactType::TravelAgent,
    ]);
    expect($upgraded->id)->toBe($passenger->id);
    expect($upgraded->fresh()?->type)->toBe(ContactType::TravelAgent);

    $unchanged = app(ResolveContact::class)->handle([
        'name' => 'Passenger',
        'email' => 'type@iconic.test',
        'type' => ContactType::CorporateCharter,
    ]);
    expect($unchanged->fresh()?->type)->toBe(ContactType::TravelAgent);

    $charter = app(ResolveContact::class)->handle([
        'name' => 'Charter',
        'email' => 'charter-type@iconic.test',
        'type' => ContactType::CorporateCharter,
    ]);
    expect($charter->type)->toBe(ContactType::CorporateCharter);

    app(ResolveContact::class)->handle([
        'name' => 'Charter',
        'email' => 'charter-type@iconic.test',
        'type' => ContactType::DirectPassenger,
    ]);
    expect($charter->fresh()?->type)->toBe(ContactType::CorporateCharter);
});

test('without an email resolve matches the oldest phone_e164 and never name alone', function (): void {
    $this->actingAs(managerUser());

    $first = app(ResolveContact::class)->handle([
        'name' => 'Ada Lovelace',
        'phone' => '(650) 253-0000',
        'country' => 'US',
    ]);
    $second = app(ResolveContact::class)->handle([
        'name' => 'Someone Else',
        'phone' => '6502530000',
        'country' => 'US',
    ]);

    expect($second->id)->toBe($first->id);
    expect($first->phone_e164)->toBe('+16502530000');
    expect($first->name)->toBe('Ada Lovelace');

    $namedA = app(ResolveContact::class)->handle(['name' => 'Same Name']);
    $namedB = app(ResolveContact::class)->handle(['name' => 'Same Name']);

    expect($namedB->id)->not->toBe($namedA->id);
});
