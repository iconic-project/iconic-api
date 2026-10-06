<?php

declare(strict_types=1);

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoConversationsSeeder;

test('demo conversations attach to hotel contacts and stay idempotent', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoConversationsSeeder::class);

    expect(Conversation::query()->count())->toBe(4)
        ->and(Message::query()->count())->toBe(7)
        ->and(Contact::query()->where('email', 'unlinked.inbox@iconic.test')->exists())->toBeFalse()
        ->and(Contact::query()->where('email', 'demo.unlinked@iconic.test')->exists())->toBeFalse();

    $dinner = Conversation::query()->where('subject', 'Late dinner')->firstOrFail();
    expect($dinner->contact?->email)->toBe('htl-019@hotel-demo.test')
        ->and($dinner->status)->toBe(ConversationStatus::Open)
        ->and($dinner->unread)->toBeTrue()
        ->and($dinner->messages()->count())->toBe(3)
        ->and($dinner->preview())->toBe('Perfect. There will be two of us.');

    $request = Conversation::query()->where('subject', 'Request for 2 June')->firstOrFail();
    expect($request->contact?->email)->toBe('htl-008@hotel-demo.test')
        ->and($request->unread)->toBeTrue()
        ->and($request->messages()->count())->toBe(1);

    $invoice = Conversation::query()->where('subject', 'Invoice for the stay')->firstOrFail();
    expect($invoice->contact?->email)->toBe('htl-011@hotel-demo.test')
        ->and($invoice->status)->toBe(ConversationStatus::Closed)
        ->and($invoice->unread)->toBeFalse()
        ->and($invoice->messages()->where('direction', MessageDirection::Out->value)->count())->toBe(1);

    $unlinked = Conversation::query()->where('subject', 'September dates')->firstOrFail();
    expect($unlinked->contact_id)->toBeNull()
        ->and($unlinked->unread)->toBeTrue()
        ->and($unlinked->latestInbound?->from)->toBe('demo.unlinked@iconic.test');
});
