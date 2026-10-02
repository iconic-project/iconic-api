<?php

declare(strict_types=1);

use App\Actions\Documents\PrepareIssueDocument;
use App\Actions\Documents\SendDocument;
use App\Enums\BookingStatus;
use App\Enums\DocumentKind;
use App\Models\Booking;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Storage::disk('documents')->deleteDirectory('/');
});

test('a document send arrives in Mailpit', function (): void {
    $reachable = @fsockopen('mailpit', 8025, $errno, $errstr, 1);

    if ($reachable === false) {
        $this->markTestSkipped('Mailpit is not reachable');
    }

    fclose($reachable);

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'mailpit',
        'mail.mailers.smtp.port' => 1025,
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
    ]);

    $actor = adminUser();
    $booking = Booking::factory()->create([
        'departure_id' => ReservationFixtures::anamaraDeparture('2029-01-07')->id,
        'cabin_id' => ReservationFixtures::anamaraDeparture('2029-01-07')->property->cabins->firstWhere('code', 'S3')?->id,
        'status' => BookingStatus::Confirmed,
        'reference' => 'ANK-2026-7100',
    ]);
    $to = $booking->contact->email;
    $document = app(PrepareIssueDocument::class)->handle($booking, DocumentKind::Summary, actor: $actor);

    app(SendDocument::class)->handle($booking, $document, $actor);

    $found = false;

    foreach (range(1, 10) as $ignored) {
        $search = Http::get('http://mailpit:8025/api/v1/search', [
            'query' => 'to:'.$to,
            'limit' => 20,
        ]);

        if ($search->successful()) {
            foreach ($search->json('messages') ?? [] as $message) {
                if (($message['Subject'] ?? '') === 'Your Iconic booking summary — ANK-2026-7100') {
                    $found = true;
                    break 2;
                }
            }
        }

        usleep(200_000);
    }

    expect($found)->toBeTrue();
})->group('mailpit');
