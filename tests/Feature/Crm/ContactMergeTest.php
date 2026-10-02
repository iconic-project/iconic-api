<?php

declare(strict_types=1);

use App\Actions\Contacts\ResolveContact;
use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\BehaviouralEvent;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\CharterEnquiry;
use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Crm\ContactReferences;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @return array{survivor: Contact, loser: Contact, booking: Booking, later: Booking, waitlist: WaitlistEntry, group: Group, enquiry: CharterEnquiry}
 */
function mergeFixture(): array
{
    $survivor = Contact::factory()->create([
        'name' => 'Older Guest',
        'email' => null,
        'phone' => null,
        'country' => null,
    ]);
    $loser = Contact::factory()->create([
        'name' => 'Newer Guest',
        'email' => 'loser@iconic.test',
        'phone' => '6502530000',
        'country' => 'US',
        'phone_e164' => '+16502530000',
    ]);

    $departure = ReservationFixtures::anamaraDeparture('2027-12-05');
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->yacht->cabins->firstWhere('code', 'S1')?->id,
        'contact_id' => $loser->id,
        'status' => BookingStatus::Confirmed,
    ]);
    $later = Booking::factory()->create([
        'departure_id' => ReservationFixtures::anamaraDeparture('2027-12-12')->id,
        'cabin_id' => ReservationFixtures::anamaraDeparture('2027-12-12')->yacht->cabins->firstWhere('code', 'S2')?->id,
        'contact_id' => $loser->id,
        'status' => BookingStatus::Requested,
    ]);
    $waitlist = WaitlistEntry::factory()->create([
        'departure_id' => $departure->id,
        'contact_id' => $loser->id,
    ]);
    $group = Group::factory()->create([
        'departure_id' => $departure->id,
        'coordinator_contact_id' => $loser->id,
    ]);
    $enquiry = CharterEnquiry::factory()->create([
        'contact_id' => $loser->id,
    ]);

    return compact('survivor', 'loser', 'booking', 'later', 'waitlist', 'group', 'enquiry');
}

test('merge repoints every contact-bearing table, fills empty fields and keeps the older id', function (): void {
    $fixture = mergeFixture();
    $actor = managerUser();

    $response = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$fixture['loser']->id.'/merge', [
            'contact_id' => $fixture['survivor']->id,
            'reason' => 'Same person',
        ])
        ->assertOk();

    assertNoSensitiveFields($response);

    expect($response->json('swapped'))->toBeTrue();
    expect($response->json('merge.survivor_id'))->toBe($fixture['survivor']->id);
    expect($response->json('merge.loser_id'))->toBe($fixture['loser']->id);
    expect($response->json('contact.email'))->toBe('loser@iconic.test');
    expect($response->json('contact.phone_e164'))->toBe('+16502530000');
    expect($response->json('contact.country'))->toBe('US');

    expect($fixture['booking']->fresh()?->contact_id)->toBe($fixture['survivor']->id);
    expect($fixture['later']->fresh()?->contact_id)->toBe($fixture['survivor']->id);
    expect($fixture['waitlist']->fresh()?->contact_id)->toBe($fixture['survivor']->id);
    expect($fixture['group']->fresh()?->coordinator_contact_id)->toBe($fixture['survivor']->id);
    expect($fixture['enquiry']->fresh()?->contact_id)->toBe($fixture['survivor']->id);

    expect($fixture['loser']->fresh()?->merged_into_id)->toBe($fixture['survivor']->id);
    expect($fixture['loser']->fresh()?->email)->toBeNull();
    expect(ContactAlias::query()->where('alias_id', $fixture['loser']->id)->exists())->toBeTrue();

    $show = $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$fixture['loser']->id)
        ->assertOk();

    expect($show->json('id'))->toBe($fixture['survivor']->id);
    expect($show->json('resolved_from_alias'))->toBeTrue();
    expect($show->json('alias_id'))->toBe($fixture['loser']->id);

    $this->actingAs($actor)
        ->getJson('/api/crm/contacts')
        ->assertOk()
        ->assertJsonMissing(['id' => $fixture['loser']->id]);

    expect(ChangeHistory::query()->where('event', 'contact.merged')->count())->toBe(2);

    $tables = array_column(ContactReferences::tables(), 'table');
    expect($tables)->toBe(['bookings', 'groups', 'waitlist_entries', 'charter_enquiries', 'behavioural_events', 'contact_consents', 'deals', 'crm_tasks', 'contact_activities']);
});

test('merge moves behavioural events and unmerge restores them', function (): void {
    $fixture = mergeFixture();
    $event = BehaviouralEvent::factory()->create([
        'contact_id' => $fixture['loser']->id,
        'name' => BehaviouralEventName::PageView,
    ]);
    $actor = managerUser();

    $mergeId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$fixture['survivor']->id.'/merge', [
            'contact_id' => $fixture['loser']->id,
            'reason' => 'Same person',
        ])
        ->assertOk()
        ->json('merge.id');

    expect($event->fresh()?->contact_id)->toBe($fixture['survivor']->id);

    $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$mergeId.'/undo', [
            'reason' => 'Split them',
        ])
        ->assertOk();

    expect($event->fresh()?->contact_id)->toBe($fixture['loser']->id);
});

test('merge does not overwrite a value the survivor already has', function (): void {
    $survivor = Contact::factory()->create([
        'email' => 'keep@iconic.test',
        'phone' => '020 7031 3000',
        'country' => 'GB',
        'phone_e164' => '+442070313000',
    ]);
    $loser = Contact::factory()->create([
        'email' => 'drop@iconic.test',
        'phone' => '6502530000',
        'country' => 'US',
        'phone_e164' => '+16502530000',
    ]);

    $this->actingAs(managerUser())
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Keep survivor fields',
        ])
        ->assertOk()
        ->assertJsonPath('contact.email', 'keep@iconic.test')
        ->assertJsonPath('contact.country', 'GB');

    expect($loser->fresh()?->email)->toBe('drop@iconic.test');
});

test('sales exec cannot merge and a crm viewer without the permission is forbidden', function (): void {
    $survivor = Contact::factory()->create();
    $loser = Contact::factory()->create();

    $this->actingAs(salesExecUser())
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'No',
        ])
        ->assertForbidden();

    $role = Role::factory()->create([
        'permissions' => [Permission::PanelCrm],
    ]);
    $viewer = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($viewer)
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'No',
        ])
        ->assertForbidden();
});

test('unmerge restores recorded rows and leaves a booking created after the merge', function (): void {
    $fixture = mergeFixture();
    $actor = managerUser();

    $mergeId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$fixture['survivor']->id.'/merge', [
            'contact_id' => $fixture['loser']->id,
            'reason' => 'Merge them',
        ])
        ->assertOk()
        ->json('merge.id');

    $after = Booking::factory()->create([
        'departure_id' => ReservationFixtures::anamaraDeparture('2028-01-02')->id,
        'cabin_id' => ReservationFixtures::anamaraDeparture('2028-01-02')->yacht->cabins->firstWhere('code', 'S3')?->id,
        'contact_id' => $fixture['survivor']->id,
        'status' => BookingStatus::Confirmed,
    ]);

    $undo = $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$mergeId.'/undo', [
            'reason' => 'Split them',
        ])
        ->assertOk();

    assertNoSensitiveFields($undo);
    expect($undo->json('skipped_rows'))->toBe([]);

    expect($fixture['booking']->fresh()?->contact_id)->toBe($fixture['loser']->id);
    expect($fixture['later']->fresh()?->contact_id)->toBe($fixture['loser']->id);
    expect($after->fresh()?->contact_id)->toBe($fixture['survivor']->id);
    expect($fixture['loser']->fresh()?->merged_into_id)->toBeNull();
    expect($fixture['loser']->fresh()?->email)->toBe('loser@iconic.test');
    expect($fixture['survivor']->fresh()?->email)->toBeNull();

    $this->actingAs($actor)
        ->getJson('/api/crm/contacts/'.$fixture['loser']->id)
        ->assertOk()
        ->assertJsonPath('id', $fixture['loser']->id)
        ->assertJsonPath('resolved_from_alias', false);

    expect(ChangeHistory::query()->where('event', 'contact.unmerged')->count())->toBe(2);
});

test('unmerge skips a recorded booking that was moved to a third contact', function (): void {
    $fixture = mergeFixture();
    $third = Contact::factory()->create(['email' => 'third@iconic.test']);
    $actor = managerUser();

    $mergeId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$fixture['survivor']->id.'/merge', [
            'contact_id' => $fixture['loser']->id,
            'reason' => 'Merge them',
        ])
        ->json('merge.id');

    $fixture['booking']->update(['contact_id' => $third->id]);

    $undo = $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$mergeId.'/undo', [
            'reason' => 'Split them',
        ])
        ->assertOk();

    expect($undo->json('skipped_rows'))->toHaveCount(1);
    expect($undo->json('skipped_rows.0.table'))->toBe('bookings');
    expect($undo->json('skipped_rows.0.id'))->toBe($fixture['booking']->id);
    expect($fixture['booking']->fresh()?->contact_id)->toBe($third->id);
    expect($fixture['later']->fresh()?->contact_id)->toBe($fixture['loser']->id);

    $history = ChangeHistory::query()->where('event', 'contact.unmerged')->firstOrFail();
    expect($history->reason)->toContain('bookings#'.$fixture['booking']->id);
});

test('unmerge after 30 days is refused and an erased merge cannot be undone', function (): void {
    $survivor = Contact::factory()->create();
    $loser = Contact::factory()->create();
    $actor = managerUser();

    $mergeId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Merge',
        ])
        ->json('merge.id');

    $this->travel(30)->days();

    $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$mergeId.'/undo', [
            'reason' => 'Too late',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This merge is permanent.');

    $fresh = Contact::factory()->create();
    $other = Contact::factory()->create();
    $erasedId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$fresh->id.'/merge', [
            'contact_id' => $other->id,
            'reason' => 'Erase me',
        ])
        ->json('merge.id');

    DB::table('contact_merges')->where('id', $erasedId)->update([
        'erased_at' => now(),
        'merged_identifiers' => null,
        'loser_fields' => null,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$erasedId.'/undo', [
            'reason' => 'Cannot',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This merge has been erased and cannot be undone.');
});

test('a chained merge cannot be undone until the later merge is undone', function (): void {
    $a = Contact::factory()->create(['email' => 'a@iconic.test']);
    $b = Contact::factory()->create(['email' => 'b@iconic.test']);
    $c = Contact::factory()->create(['email' => 'c@iconic.test']);
    $actor = managerUser();

    $firstId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$b->id.'/merge', [
            'contact_id' => $c->id,
            'reason' => 'B+C',
        ])
        ->json('merge.id');

    $secondId = $this->actingAs($actor)
        ->postJson('/api/crm/contacts/'.$a->id.'/merge', [
            'contact_id' => $b->id,
            'reason' => 'A+B',
        ])
        ->json('merge.id');

    $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$firstId.'/undo', [
            'reason' => 'Too soon',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Undo merge #'.$secondId.' first — this survivor was later merged onward.');

    $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$secondId.'/undo', [
            'reason' => 'Undo later first',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson('/api/crm/contact-merges/'.$firstId.'/undo', [
            'reason' => 'Now the first',
        ])
        ->assertOk();
});

test('resolve follows a merged loser email to the survivor', function (): void {
    $survivor = Contact::factory()->create(['email' => 'keep-res@iconic.test']);
    $loser = Contact::factory()->create(['email' => 'follow@iconic.test']);

    $this->actingAs(managerUser())
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Follow email',
        ])
        ->assertOk();

    $resolved = app(ResolveContact::class)->handle([
        'name' => 'Whoever',
        'email' => 'FOLLOW@iconic.test',
    ]);

    expect($resolved->id)->toBe($survivor->id);
});

test('the merge log lists merges for the identity screen', function (): void {
    $survivor = Contact::factory()->create();
    $loser = Contact::factory()->create();

    $this->actingAs(managerUser())
        ->postJson('/api/crm/contacts/'.$survivor->id.'/merge', [
            'contact_id' => $loser->id,
            'reason' => 'Logged',
        ])
        ->assertOk();

    $this->actingAs(salesExecUser())
        ->getJson('/api/crm/contact-merges')
        ->assertOk()
        ->assertJsonPath('data.0.reason', 'Logged');
});
