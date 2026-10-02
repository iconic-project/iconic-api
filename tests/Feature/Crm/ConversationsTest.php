<?php

declare(strict_types=1);

use App\Actions\Crm\CaptureInboundMessage;
use App\Enums\MessageDirection;
use App\Jobs\PollInboxJob;
use App\Mail\Crm\ConversationReplyMail;
use App\Models\ChangeHistory;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Delivery;
use App\Models\Message;
use App\Support\Documents\IssuerMail;
use App\Support\Mail\InboundMail;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    Mail::fake();
});

test('references append to the contact thread and a cold subject opens another', function (): void {
    $contact = Contact::factory()->create(['email' => 'guest@example.com']);
    $capture = app(CaptureInboundMessage::class);

    $first = $capture->handle(inboxMail(
        from: 'Guest@Example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T12:00:00Z'),
    ));
    $second = $capture->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Re: Cabins',
        messageId: '<b@guest.test>',
        references: ['<a@guest.test>'],
        sentAt: CarbonImmutable::parse('2026-09-23T13:00:00Z'),
    ));
    $cold = $capture->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Weather',
        messageId: '<c@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T14:00:00Z'),
    ));

    expect($first->conversation_id)->toBe($second->conversation_id)
        ->and($cold->conversation_id)->not->toBe($first->conversation_id)
        ->and($first->conversation->contact_id)->toBe($contact->id)
        ->and($first->conversation->subject)->toBe('Cabins')
        ->and(Conversation::query()->count())->toBe(2);
});

test('the same subject without headers still threads for that contact only', function (): void {
    $contact = Contact::factory()->create(['email' => 'guest@example.com']);
    $capture = app(CaptureInboundMessage::class);

    $first = $capture->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
    ));
    $second = $capture->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Fwd: Cabins',
        messageId: '<b@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T13:00:00Z'),
    ));
    $stranger = $capture->handle(inboxMail(
        from: 'other@example.com',
        subject: 'Cabins',
        messageId: '<c@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T14:00:00Z'),
    ));

    expect($second->conversation_id)->toBe($first->conversation_id)
        ->and($stranger->conversation_id)->not->toBe($first->conversation_id)
        ->and($stranger->conversation->contact_id)->toBeNull()
        ->and($first->conversation->contact_id)->toBe($contact->id);
});

test('an unknown sender stays unlinked and is not created as a contact', function (): void {
    $before = Contact::query()->count();
    $message = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Hello',
        messageId: '<s@guest.test>',
        body: 'A question about the yacht',
    ));

    expect(Contact::query()->count())->toBe($before)
        ->and(Contact::query()->where('email', 'stranger@example.com')->exists())->toBeFalse()
        ->and($message->conversation->contact_id)->toBeNull()
        ->and($message->body_text)->toBe('A question about the yacht')
        ->and($message->body_html)->toContain('A question about the yacht');
});

test('reprocessing the same message id does not duplicate the row', function (): void {
    $capture = app(CaptureInboundMessage::class);
    $mail = inboxMail(from: 'stranger@example.com', subject: 'Hello', messageId: '<same@guest.test>');
    $capture->handle($mail);
    $capture->handle($mail);
    $capture->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Hello',
        messageId: '',
        body: 'No header',
        sentAt: CarbonImmutable::parse('2026-09-23T15:00:00Z'),
    ));
    $capture->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Hello',
        messageId: '',
        body: 'No header',
        sentAt: CarbonImmutable::parse('2026-09-23T15:00:00Z'),
    ));

    expect(Message::query()->count())->toBe(2)
        ->and(Message::query()->where('message_id', 'like', 'missing:%')->count())->toBe(1)
        ->and(ChangeHistory::query()->where('event', 'conversation.message')->count())->toBe(2);
});

test('a reply sets threading headers, queues mail, and skips the delivery log', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['email' => 'guest@example.com', 'name' => 'Guest']);
    $inbound = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
        body: "Hi there\n\nOn Mon, 1 Jan 2026 Guest wrote:\n> old line",
    ));

    expect($inbound->body_text)->toBe('Hi there')
        ->and($inbound->body_html)->toContain('old line');

    $response = $this->actingAs($actor)->postJson('/api/crm/conversations/'.$inbound->conversation_id.'/reply', [
        'message' => 'The master cabin is free.',
    ]);

    $response->assertOk();
    assertNoSensitiveFields($response);

    $outbound = Message::query()->where('direction', MessageDirection::Out)->first();
    expect($outbound)->not->toBeNull()
        ->and($outbound->in_reply_to)->toBe('<a@guest.test>')
        ->and($outbound->staff_id)->toBe($actor->id)
        ->and($outbound->to)->toBe(['guest@example.com'])
        ->and($inbound->conversation->fresh()->unread)->toBeFalse()
        ->and(Delivery::query()->count())->toBe(0)
        ->and($response->json('contact_name'))->toBe('Guest')
        ->and($response->json('contact_id'))->toBe($contact->id);

    Mail::assertQueued(ConversationReplyMail::class, function (ConversationReplyMail $mail): bool {
        return $mail->record->in_reply_to === '<a@guest.test>';
    });

    $queued = Mail::queued(ConversationReplyMail::class)->first();
    expect($queued)->toBeInstanceOf(ConversationReplyMail::class);
    $envelope = $queued->envelope();
    expect($envelope->replyTo[0]->address)->toBe(IssuerMail::replyTo())
        ->and($envelope->from->address)->toBe((string) config('mail.from.address'))
        ->and($envelope->to[0]->address)->toBe('guest@example.com');

    $email = new Email;
    foreach ($envelope->using as $callback) {
        $callback($email);
    }

    expect($email->getHeaders()->get('In-Reply-To')?->getBodyAsString())->toBe('<a@guest.test>')
        ->and($email->getHeaders()->get('References')?->getBodyAsString())->toBe('<a@guest.test>');
});

test('opening a conversation marks it read and a bad status is rejected', function (): void {
    $actor = salesExecUser();
    $message = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
    ));
    Contact::factory()->create(['email' => 'guest@example.com']);

    $shown = $this->actingAs($actor)
        ->getJson('/api/crm/conversations/'.$message->conversation_id)
        ->assertOk();
    assertNoSensitiveFields($shown);
    expect($shown->json('unread'))->toBeFalse()
        ->and($shown->json('messages.0.direction'))->toBe('IN')
        ->and($shown->json('messages.0.body_text'))->toBe('Hi there');

    $this->actingAs($actor)
        ->getJson('/api/crm/conversations/'.$message->conversation_id)
        ->assertOk();
    expect(ChangeHistory::query()->where('event', 'conversation.read')->count())->toBe(1);

    $closed = $this->actingAs($actor)->patchJson('/api/crm/conversations/'.$message->conversation_id, [
        'status' => 'CLOSED',
    ])->assertOk();
    assertNoSensitiveFields($closed);
    expect($closed->json('status'))->toBe('CLOSED');

    $this->actingAs($actor)->patchJson('/api/crm/conversations/'.$message->conversation_id, [
        'status' => 'CLOSED',
    ])->assertOk();
    expect(ChangeHistory::query()->where('event', 'conversation.status_changed')->count())->toBe(1);

    $this->actingAs($actor)->patchJson('/api/crm/conversations/'.$message->conversation_id, [
        'status' => 'ARCHIVED',
    ])->assertStatus(422);
});

test('the inbox list filters and hides itself from users without panel.crm', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['email' => 'guest@example.com', 'name' => 'Guest']);
    $older = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T10:00:00Z'),
    ));
    $newer = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Hello',
        messageId: '<b@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T18:00:00Z'),
    ));

    $index = $this->actingAs($actor)->getJson('/api/crm/conversations')->assertOk();
    assertNoSensitiveFields($index);
    expect($index->json('data.0.id'))->toBe($newer->conversation_id)
        ->and($index->json('data.0.from'))->toBe('stranger@example.com')
        ->and($index->json('data.0.contact_name'))->toBeNull()
        ->and($index->json('data.1.contact_name'))->toBe('Guest')
        ->and($index->json('data.0.preview'))->toBe('Hi there')
        ->and($index->json('data.0.message_count'))->toBe(1);

    $this->actingAs($actor)->patchJson('/api/crm/conversations/'.$older->conversation_id, [
        'status' => 'CLOSED',
    ])->assertOk();

    $open = $this->actingAs($actor)->getJson('/api/crm/conversations?status=OPEN')->assertOk();
    expect($open->json('data'))->toHaveCount(1)
        ->and($open->json('data.0.id'))->toBe($newer->conversation_id);

    $mine = $this->actingAs($actor)->getJson('/api/crm/conversations?contact_id='.$contact->id)->assertOk();
    expect($mine->json('data'))->toHaveCount(1)
        ->and($mine->json('data.0.id'))->toBe($older->conversation_id);

    $this->actingAs(externalFinanceUser())->getJson('/api/crm/conversations')->assertForbidden();
});

test('linking an unmatched thread attaches it, or folds it into the contact subject thread', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['email' => 'guest@example.com', 'name' => 'Guest']);
    $known = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T10:00:00Z'),
    ));
    $other = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Weather',
        messageId: '<w@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T11:00:00Z'),
    ));
    $same = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Re: Cabins',
        messageId: '<s@guest.test>',
        sentAt: CarbonImmutable::parse('2026-09-23T12:00:00Z'),
    ));

    $linked = $this->actingAs($actor)->postJson('/api/crm/conversations/'.$other->conversation_id.'/link-contact', [
        'contact_id' => $contact->id,
    ])->assertOk();
    assertNoSensitiveFields($linked);
    expect($linked->json('id'))->toBe($other->conversation_id)
        ->and($linked->json('contact_name'))->toBe('Guest');

    $folded = $this->actingAs($actor)->postJson('/api/crm/conversations/'.$same->conversation_id.'/link-contact', [
        'contact_id' => $contact->id,
    ])->assertOk();
    expect($folded->json('id'))->toBe($known->conversation_id)
        ->and(Conversation::query()->whereKey($same->conversation_id)->exists())->toBeFalse()
        ->and(Message::query()->where('conversation_id', $known->conversation_id)->count())->toBe(2);

    $this->actingAs($actor)->postJson('/api/crm/conversations/'.$known->conversation_id.'/link-contact', [
        'contact_id' => $contact->id,
    ])->assertStatus(422);
});

test('a linked message is one timeline line and does not copy the body', function (): void {
    $actor = salesExecUser();
    $contact = Contact::factory()->create(['email' => 'guest@example.com', 'name' => 'Guest']);
    $message = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'guest@example.com',
        subject: 'Cabins',
        messageId: '<a@guest.test>',
        body: 'The cabin is lovely',
    ));

    $response = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline')
        ->assertOk();
    assertNoSensitiveFields($response);

    $item = collect($response->json('data'))->firstWhere('kind', 'conversation.message');
    expect($item)->not->toBeNull()
        ->and($item['title'])->toBe('Email received')
        ->and($item['detail'])->toBe('Cabins')
        ->and($item['link']['type'])->toBe('conversation')
        ->and($item['link']['id'])->toBe($message->conversation_id)
        ->and(json_encode($item))->not->toContain('lovely');

    $stranger = app(CaptureInboundMessage::class)->handle(inboxMail(
        from: 'stranger@example.com',
        subject: 'Secret subject',
        messageId: '<s@guest.test>',
        body: 'do not show this body',
    ));
    $again = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$contact->id.'/timeline')
        ->assertOk();
    expect(collect($again->json('data'))->pluck('detail'))->not->toContain('Secret subject')
        ->and($stranger->conversation->contact_id)->toBeNull();
});

test('mailpit polling captures a message once and strips the quoted reply', function (): void {
    Contact::factory()->create(['email' => 'guest@example.com']);
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/api/v1/message/')) {
            return Http::response([
                'ID' => 'mp-1',
                'MessageID' => '<poll@guest.test>',
                'From' => ['Address' => 'guest@example.com', 'Name' => 'Guest'],
                'To' => [['Address' => 'hello@example.com']],
                'Subject' => 'Re: Cabins',
                'HTML' => '<p>Hi there</p><blockquote>old line</blockquote>',
                'Text' => "Hi there\n\nOn Mon, 1 Jan 2026 Guest wrote:\n> old line",
                'Date' => '2026-09-23T12:00:00Z',
                'Headers' => [
                    'Message-ID' => ['<poll@guest.test>'],
                ],
            ]);
        }

        if (str_contains($url, '/api/v1/messages')) {
            return Http::response([
                'messages' => [[
                    'ID' => 'mp-1',
                    'MessageID' => '<poll@guest.test>',
                    'From' => ['Address' => 'guest@example.com'],
                    'To' => [['Address' => 'hello@example.com']],
                    'Subject' => 'Re: Cabins',
                    'Created' => '2026-09-23T12:00:00Z',
                ]],
            ]);
        }

        return Http::response('unexpected '.$url, 500);
    });

    app()->call([new PollInboxJob, 'handle']);
    app()->call([new PollInboxJob, 'handle']);

    $message = Message::query()->where('message_id', '<poll@guest.test>')->first();
    expect(Message::query()->count())->toBe(1)
        ->and($message)->not->toBeNull()
        ->and($message->body_text)->toBe('Hi there')
        ->and($message->body_html)->toContain('old line')
        ->and($message->conversation->contact?->email)->toBe('guest@example.com')
        ->and($message->conversation->subject)->toBe('Cabins')
        ->and($message->conversation->unread)->toBeTrue();
});

test('graph polling reads the inbox and empty keys skip the poll', function (): void {
    config([
        'iconic.inbox.driver' => 'graph',
        'services.graph.tenant' => 'tenant',
        'services.graph.client_id' => 'client',
        'services.graph.client_secret' => 'secret',
        'services.graph.mailbox' => 'hello@example.com',
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, 'oauth2/v2.0/token')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 3600]);
        }

        if (str_contains($url, 'mailFolders/inbox/messages')) {
            return Http::response([
                'value' => [[
                    'internetMessageId' => '<graph@guest.test>',
                    'subject' => 'Graph hello',
                    'receivedDateTime' => '2026-09-23T12:00:00Z',
                    'from' => ['emailAddress' => ['address' => 'graph-guest@example.com']],
                    'toRecipients' => [['emailAddress' => ['address' => 'hello@example.com']]],
                    'body' => ['contentType' => 'html', 'content' => '<p>From graph</p>'],
                    'internetMessageHeaders' => [
                        ['name' => 'In-Reply-To', 'value' => '<none@guest.test>'],
                    ],
                ]],
            ]);
        }

        return Http::response('unexpected '.$url, 500);
    });

    app()->call([new PollInboxJob, 'handle']);

    expect(Message::query()->where('message_id', '<graph@guest.test>')->exists())->toBeTrue()
        ->and(Message::query()->count())->toBe(1);

    config([
        'iconic.inbox.driver' => 'graph',
        'services.graph.tenant' => '',
        'services.graph.client_id' => '',
        'services.graph.client_secret' => '',
        'services.graph.mailbox' => '',
    ]);
    Bus::fake();
    $this->artisan('iconic:inbox-poll')->assertSuccessful()->expectsOutputToContain('Graph keys are empty');
    Bus::assertNothingDispatched();
});

test('the inbox poll is scheduled every minute', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('iconic:inbox-poll');
});

function inboxMail(
    string $from,
    string $subject,
    string $messageId,
    string $body = 'Hi there',
    ?string $inReplyTo = null,
    array $references = [],
    ?CarbonImmutable $sentAt = null,
): InboundMail {
    return new InboundMail(
        messageId: $messageId,
        from: $from,
        to: ['hello@example.com'],
        subject: $subject,
        bodyHtml: '<p>'.e($body).'</p>',
        bodyText: $body,
        inReplyTo: $inReplyTo,
        references: $references,
        sentAt: $sentAt ?? CarbonImmutable::parse('2026-09-23T12:00:00Z'),
    );
}
