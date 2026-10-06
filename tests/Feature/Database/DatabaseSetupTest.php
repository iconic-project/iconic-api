<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('default connection is mysql iconic_test', function (): void {
    $connection = config('database.default');
    $driver = config("database.connections.{$connection}.driver");
    $database = config("database.connections.{$connection}.database");

    expect($driver)->toBe('mysql', "Tests must use the mysql driver on the default connection, got [{$driver}]. Check phpunit.xml (DB_CONNECTION=mysql).");
    expect($database)->toBe('iconic_test', "Tests must use the iconic_test database, got [{$database}]. Check phpunit.xml (DB_DATABASE=iconic_test) so tests never hit the app database.");
});

test('core tables exist after migration', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue('users table missing after migration.');
    expect(Schema::hasTable('sessions'))->toBeTrue('sessions table missing after migration.');
    expect(Schema::hasTable('cache'))->toBeTrue('cache table missing after migration.');
    expect(Schema::hasTable('jobs'))->toBeTrue('jobs table missing after migration.');
    expect(Schema::hasTable('test_config_versions'))->toBeTrue(
        'test_config_versions missing — tests/database/migrations must be registered for the whole suite.',
    );
    expect(Schema::hasTable('rate_versions'))->toBeTrue('rate_versions table missing after migration.');
    expect(Schema::hasTable('engine_settings_versions'))->toBeTrue('engine_settings_versions table missing after migration.');
    expect(Schema::hasTable('business_rule_versions'))->toBeTrue('business_rule_versions table missing after migration.');
    expect(Schema::hasTable('extra_versions'))->toBeTrue('extra_versions table missing after migration.');
    expect(Schema::hasTable('booking_extras'))->toBeTrue('booking_extras table missing after migration.');
    expect(Schema::hasTable('properties'))->toBeTrue('properties table missing after migration.');
    expect(Schema::hasTable('rooms'))->toBeTrue('rooms table missing after migration.');
    expect(Schema::hasTable('room_types'))->toBeTrue('room_types table missing after migration.');
    expect(Schema::hasTable('archive_itineraries'))->toBeTrue('archive_itineraries table missing after migration.');
    expect(Schema::hasTable('itineraries'))->toBeFalse();
    expect(Schema::hasTable('archive_departures'))->toBeTrue('archive_departures table missing after migration.');
    expect(Schema::hasTable('departures'))->toBeFalse();
    expect(Schema::hasTable('calendar_date_hosts'))->toBeTrue(
        'calendar_date_hosts missing — tests/database/migrations must be registered for the whole suite.',
    );
    expect(Schema::hasTable('archive_cabin_claims'))->toBeTrue('archive_cabin_claims table missing after migration.');
    expect(Schema::hasTable('cabin_claims'))->toBeFalse();
    expect(Schema::hasTable('room_night_claims'))->toBeTrue('room_night_claims table missing after migration.');
    expect(Schema::hasTable('guests'))->toBeTrue('guests table missing after migration.');
    expect(Schema::hasTable('consents'))->toBeTrue('consents table missing after migration.');
    expect(Schema::hasTable('internal_blocks'))->toBeTrue('internal_blocks table missing after migration.');
    expect(Schema::hasTable('claim_holders'))->toBeTrue(
        'claim_holders missing — tests/database/migrations must be registered for the whole suite.',
    );
    expect(Schema::hasTable('sensitive_proof_hosts'))->toBeTrue(
        'sensitive_proof_hosts missing — tests/database/migrations must be registered for the whole suite.',
    );
});
