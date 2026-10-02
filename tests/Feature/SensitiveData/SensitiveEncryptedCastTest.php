<?php

declare(strict_types=1);

use App\Casts\SensitiveEncrypted;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\SensitiveEncrypted\SensitiveProofHost;

afterEach(function (): void {
    SensitiveEncrypted::flushEncrypter();
});

test('the raw column is ciphertext and the model reads plaintext', function (): void {
    $host = SensitiveProofHost::query()->create(['secret' => 'AB1234567']);

    $raw = DB::table('sensitive_proof_hosts')->where('id', $host->id)->value('secret');

    expect($raw)->toBeString();
    expect($raw)->not->toBe('AB1234567');
    expect($raw)->not->toContain('AB1234567');
    expect($host->fresh()?->secret)->toBe('AB1234567');
});

test('null and empty string store as null', function (): void {
    $empty = SensitiveProofHost::query()->create(['secret' => '']);
    $missing = SensitiveProofHost::query()->create(['secret' => null]);

    expect(DB::table('sensitive_proof_hosts')->where('id', $empty->id)->value('secret'))->toBeNull();
    expect(DB::table('sensitive_proof_hosts')->where('id', $missing->id)->value('secret'))->toBeNull();
    expect($empty->fresh()?->secret)->toBeNull();
    expect($missing->fresh()?->secret)->toBeNull();
});

test('a different sensitive data key cannot decrypt', function (): void {
    $host = SensitiveProofHost::query()->create(['secret' => 'AB1234567']);

    SensitiveEncrypted::flushEncrypter();
    config(['sensitive.key' => 'base64:'.base64_encode('iconic-other-sensitive-key-32!!')]);

    expect(fn () => $host->fresh()?->secret)->toThrow(DecryptException::class);
});

test('rotating APP_KEY leaves the ciphertext readable', function (): void {
    $host = SensitiveProofHost::query()->create(['secret' => 'AB1234567']);

    config(['app.key' => 'base64:'.base64_encode('iconic-rotated-app-key-32bytes!')]);

    expect($host->fresh()?->secret)->toBe('AB1234567');
});

test('a missing sensitive data key throws a clear exception', function (): void {
    SensitiveEncrypted::flushEncrypter();
    config(['sensitive.key' => '']);

    expect(fn () => SensitiveProofHost::query()->create(['secret' => 'AB1234567']))
        ->toThrow(RuntimeException::class, 'SENSITIVE_DATA_KEY is not set');
});
