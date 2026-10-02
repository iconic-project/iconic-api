<?php

declare(strict_types=1);

use App\Support\HealthChecker;
use RuntimeException;

test('health returns 200 when every check is ok', function (): void {
    $response = $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('app', 'iconic-api')
        ->assertJsonPath('checks.db', 'ok')
        ->assertJsonPath('checks.redis', 'ok')
        ->assertJsonPath('checks.queue', 'ok')
        ->assertJsonStructure(['time']);

    expect($response->json('time'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
});

test('health returns 503 when the redis check fails', function (): void {
    $this->partialMock(HealthChecker::class, function ($mock): void {
        $mock->shouldReceive('redis')->once()->andThrow(new RuntimeException('redis down'));
    });

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis', 'fail')
        ->assertJsonPath('checks.db', 'ok')
        ->assertJsonPath('checks.queue', 'ok');
});
