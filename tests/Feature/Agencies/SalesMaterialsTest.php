<?php

declare(strict_types=1);

use App\Models\AgencyUser;
use App\Models\ChangeHistory;
use App\Models\SalesMaterial;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    Storage::fake('materials');
});

function materialBytes(string $name, string $contents, string $mime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'smat');

    if ($path === false) {
        throw new RuntimeException('Could not create a temp file.');
    }

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $mime, null, true);
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

function zipBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'szip');

    if ($path === false) {
        throw new RuntimeException('Could not create a temp file.');
    }

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'iconic');
    $zip->close();

    $contents = file_get_contents($path);
    unlink($path);

    if ($contents === false) {
        throw new RuntimeException('Could not read the zip.');
    }

    return $contents;
}

function asPortal(AgencyUser $user): TestCase
{
    test()->flushSession();
    Auth::forgetGuards();

    return test()->actingAs($user, 'agency');
}

function asRms(User $user): TestCase
{
    test()->flushSession();
    Auth::forgetGuards();
    Auth::shouldUse('web');

    return test()->actingAs($user, 'web')->withHeaders([
        'Origin' => 'http://test',
        'Referer' => 'http://test',
        'Accept' => 'application/json',
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function postMaterial(User $user, array $fields): TestResponse
{
    return test()->actingAs($user)->post('/api/rms/sales-materials', $fields, [
        'Accept' => 'application/json',
    ]);
}

test('upload accepts pdf png jpg mp4 and zip by content and rejects a mismatched type', function (): void {
    $manager = managerUser();

    $mp4 = pack('N', 20).'ftypmp42'.pack('N', 0).'mp42';

    $accepted = [
        ['title' => 'Fact sheet', 'kind' => 'FACT_SHEET', 'file' => materialBytes('notes.bin', pdfBytes(), 'application/octet-stream')],
        ['title' => 'Deck photo', 'kind' => 'PHOTOGRAPHY', 'file' => UploadedFile::fake()->image('photo.png')],
        ['title' => 'Cabin', 'kind' => 'PHOTOGRAPHY', 'file' => UploadedFile::fake()->image('cabin.jpg')],
        ['title' => 'Film', 'kind' => 'VIDEO', 'file' => materialBytes('film.bin', $mp4, 'application/octet-stream')],
        ['title' => 'Press kit', 'kind' => 'OTHER', 'file' => materialBytes('kit.bin', zipBytes(), 'application/octet-stream')],
    ];

    foreach ($accepted as $fields) {
        postMaterial($manager, $fields)->assertCreated()->assertJsonPath('published', true);
    }

    expect(SalesMaterial::query()->count())->toBe(5);

    postMaterial($manager, [
        'title' => 'Not a pdf',
        'kind' => 'FACT_SHEET',
        'file' => UploadedFile::fake()->create('deck.pdf', 12, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors(['file']);

    expect(SalesMaterial::query()->where('title', 'Not a pdf')->exists())->toBeFalse();
});

test('an oversized file is rejected with the stated limit', function (): void {
    $response = postMaterial(managerUser(), [
        'title' => 'Huge',
        'kind' => 'FACT_SHEET',
        'file' => UploadedFile::fake()->create('huge.pdf', 51201, 'application/pdf'),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['file']);

    expect(collect($response->json('errors.file'))->contains(
        fn (mixed $message): bool => is_string($message) && str_contains($message, '50 MB'),
    ))->toBeTrue();
    expect(SalesMaterial::query()->count())->toBe(0);
    expect(Storage::disk('materials')->allFiles())->toBe([]);
});

test('a second upload of the same title is a new published version and the old file stays', function (): void {
    $manager = managerUser();
    $agency = approvedAgency();

    $first = postMaterial($manager, [
        'title' => '  Fact sheet  ',
        'kind' => 'FACT_SHEET',
        'file' => materialBytes('a.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated()->assertJsonPath('title', 'Fact sheet')->assertJsonPath('version', 1)->json('id');

    $second = postMaterial($manager, [
        'title' => 'Fact sheet',
        'kind' => 'BRAND_DECK',
        'file' => materialBytes('b.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('published', true)->json('id');

    $old = SalesMaterial::query()->findOrFail($first);
    $current = SalesMaterial::query()->findOrFail($second);

    expect($old->published)->toBeFalse()
        ->and($old->file_path)->not->toBeNull()
        ->and(Storage::disk('materials')->exists((string) $old->file_path))->toBeTrue()
        ->and($current->published)->toBeTrue()
        ->and(Storage::disk('materials')->exists((string) $current->file_path))->toBeTrue();

    expect(ChangeHistory::query()->where('event', 'sales_material.uploaded')->count())->toBe(2);
    expect(ChangeHistory::query()->where('event', 'sales_material.unpublished')->where('subject_id', $old->id)->exists())->toBeTrue();

    postMaterial($manager, [
        'title' => 'fact sheet',
        'kind' => 'FACT_SHEET',
        'agency_id' => $agency->id,
        'file' => materialBytes('c.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('agency_id', $agency->id);

    $this->actingAs($manager)
        ->patchJson('/api/rms/sales-materials/'.$current->id)
        ->assertOk()
        ->assertJsonPath('published', false);

    $this->actingAs($manager)
        ->patchJson('/api/rms/sales-materials/'.$current->id)
        ->assertOk()
        ->assertJsonPath('published', true);

    expect(ChangeHistory::query()->where('event', 'sales_material.published')->where('subject_id', $current->id)->exists())->toBeTrue();
    expect($old->fresh()?->published)->toBeFalse();
});

test('the rms list includes every version and can be limited to one agency plus shared files', function (): void {
    $manager = managerUser();
    $agency = approvedAgency();
    $other = approvedAgency();

    postMaterial($manager, [
        'title' => 'Shared',
        'kind' => 'FACT_SHEET',
        'file' => materialBytes('s.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated();

    postMaterial($manager, [
        'title' => 'Shared',
        'kind' => 'FACT_SHEET',
        'file' => materialBytes('s2.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated();

    postMaterial($manager, [
        'title' => 'Theirs',
        'kind' => 'VIDEO',
        'agency_id' => $agency->id,
        'file' => materialBytes('t.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated();

    postMaterial($manager, [
        'title' => 'Other',
        'kind' => 'OTHER',
        'agency_id' => $other->id,
        'file' => materialBytes('o.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated();

    $listed = $this->actingAs($manager)
        ->getJson('/api/rms/sales-materials?agency_id='.$agency->id)
        ->assertOk();

    $titles = collect($listed->json('data'))->pluck('title')->all();

    expect($titles)->toContain('Shared', 'Theirs')
        ->and($titles)->not->toContain('Other');

    expect(collect($listed->json('data'))->where('title', 'Shared')->pluck('version')->sort()->values()->all())->toBe([1, 2]);
    expect(json_encode($listed->json('data')))->not->toContain('file_path');

    $this->actingAs(salesExecUser())
        ->post('/api/rms/sales-materials', [
            'title' => 'Nope',
            'kind' => 'OTHER',
            'file' => materialBytes('n.pdf', pdfBytes(), 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('the portal lists published shared and own materials and hides the rest', function (): void {
    $manager = managerUser();
    $agency = approvedAgency();
    $other = approvedAgency();
    $user = agencyUser(['name' => 'Ana Agent'], $agency);
    $otherUser = agencyUser([], $other);

    $shared = postMaterial($manager, [
        'title' => 'Fact sheet',
        'kind' => 'FACT_SHEET',
        'file' => materialBytes('s.pdf', pdfBytes(), 'application/pdf'),
    ])->json('id');

    $own = postMaterial($manager, [
        'title' => 'Rates card',
        'kind' => 'BRAND_DECK',
        'agency_id' => $agency->id,
        'file' => materialBytes('r.pdf', pdfBytes(), 'application/pdf'),
    ])->json('id');

    $hidden = postMaterial($manager, [
        'title' => 'Draft',
        'kind' => 'OTHER',
        'agency_id' => $agency->id,
        'file' => materialBytes('d.pdf', pdfBytes(), 'application/pdf'),
    ])->json('id');

    $this->actingAs($manager)->patchJson('/api/rms/sales-materials/'.$hidden)->assertOk();

    postMaterial($manager, [
        'title' => 'Other agency',
        'kind' => 'VIDEO',
        'agency_id' => $other->id,
        'file' => materialBytes('o.pdf', pdfBytes(), 'application/pdf'),
    ])->assertCreated();

    $list = asPortal($user)->getJson('/api/portal/sales-materials', portalHeaders())
        ->assertOk();

    $titles = collect($list->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['Rates card', 'Fact sheet'])
        ->and($list->json())->not->toHaveKey('meta');

    expect($list->json('data.0'))->toHaveKeys(['id', 'title', 'kind', 'size', 'version', 'updated'])
        ->and($list->json('data.0'))->not->toHaveKey('file_path')
        ->and($list->json('data.0'))->not->toHaveKey('mime');

    asPortal($user)
        ->getJson('/api/portal/me', portalHeaders())
        ->assertOk()
        ->assertJsonPath('materials_exist', true);

    asPortal($otherUser)
        ->getJson('/api/portal/me', portalHeaders())
        ->assertJsonPath('materials_exist', true);

    $download = asPortal($user)
        ->get('/api/portal/sales-materials/'.$shared.'/file', portalHeaders());

    $download->assertOk();
    expect($download->headers->get('content-type'))->toContain('application/pdf')
        ->and($download->headers->get('content-disposition'))->toContain('attachment')
        ->and($download->headers->get('cache-control'))->toContain('private')
        ->and($download->streamedContent())->toStartWith('%PDF');

    expect(ChangeHistory::query()->where('event', 'portal.material_downloaded')->count())->toBe(1);

    $row = ChangeHistory::query()->where('event', 'portal.material_downloaded')->first();
    expect($row?->subject_id)->toBe($agency->id)
        ->and($row?->actor_label)->toBe('Ana Agent ('.$user->email.')')
        ->and($row?->after)->toMatchArray([
            'material_id' => $shared,
            'title' => 'Fact sheet',
            'version' => 1,
        ]);

    asPortal($user)
        ->get('/api/portal/sales-materials/'.$own.'/file', portalHeaders())
        ->assertOk();

    $foreign = SalesMaterial::query()->where('title', 'Other agency')->firstOrFail();

    asPortal($user)
        ->get('/api/portal/sales-materials/'.$foreign->id.'/file', portalHeaders())
        ->assertNotFound();

    asPortal($user)
        ->get('/api/portal/sales-materials/'.$hidden.'/file', portalHeaders())
        ->assertNotFound();

    asPortal($user)
        ->get('/api/portal/sales-materials/999999/file', portalHeaders())
        ->assertNotFound();

    expect(ChangeHistory::query()->where('event', 'portal.material_downloaded')->count())->toBe(2);

    $beforeStaff = ChangeHistory::query()->where('event', 'portal.material_downloaded')->count();

    asRms($manager)
        ->get('/api/rms/sales-materials/'.$hidden.'/file')
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'portal.material_downloaded')->count())->toBe($beforeStaff);
});

test('portal activity lists sign-ins failures requests and downloads newest first', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    adminUser();

    $manager = managerUser();
    $agency = approvedAgency();
    $user = agencyUser(['name' => 'Ana Agent', 'email' => 'ana@agency.test'], $agency);

    $materialId = postMaterial($manager, [
        'title' => 'Fact sheet',
        'kind' => 'FACT_SHEET',
        'file' => materialBytes('s.pdf', pdfBytes(), 'application/pdf'),
    ])->json('id');

    withPortalCsrf()->postJson('/api/portal/auth/login', [
        'email' => 'ana@agency.test',
        'password' => 'password',
    ])->assertOk();

    withPortalCsrf()->postJson('/api/portal/auth/logout')->assertNoContent();

    withPortalCsrf()->postJson('/api/portal/auth/login', [
        'email' => 'ana@agency.test',
        'password' => 'wrong-password',
    ])->assertStatus(422);

    test()->flushSession();
    Auth::forgetGuards();

    withPortalCsrf()->actingAs($user, 'agency')->postJson('/api/portal/requests', [
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-25',
        'rooms' => [[
            'room_type' => 'FAM',
            'adults' => 2,
            'child_ages' => [],
            'rate_plan' => 'BAR',
        ]],
        'client' => [
            'name' => 'Elena Guest',
            'email' => 'elena-activity@guest.test',
        ],
        'client_of_record' => true,
    ])->assertCreated();

    asPortal($user)
        ->get('/api/portal/sales-materials/'.$materialId.'/file', portalHeaders())
        ->assertOk();

    Auth::forgetGuards();
    Auth::shouldUse('web');

    asRms(salesExecUser())
        ->getJson('/api/rms/agencies/'.$agency->id.'/portal-activity')
        ->assertForbidden();

    $activity = asRms($manager)
        ->getJson('/api/rms/agencies/'.$agency->id.'/portal-activity')
        ->assertOk();

    $events = collect($activity->json('data'))->pluck('event');

    expect($activity->json('data.0.event'))->toBe('portal.material_downloaded')
        ->and($activity->json('data.0.agency_user.name'))->toBe('Ana Agent')
        ->and($activity->json('data.0.agency_user.id'))->toBe($user->id)
        ->and($activity->json('data.0.material.title'))->toBe('Fact sheet')
        ->and($activity->json('data.0.material.version'))->toBe(1)
        ->and($events->all())->toBe([
            'portal.material_downloaded',
            'portal.request_created',
            'portal.sign_in_failed',
            'portal.signed_in',
        ]);

    $requestRow = collect($activity->json('data'))->firstWhere('event', 'portal.request_created');
    expect($requestRow['references'][0] ?? null)->toStartWith('HTL-R-');
    expect(json_encode($activity->json('data')))->not->toContain('wrong password');
});

test('retention leaves sales materials in place', function (): void {
    $id = postMaterial(managerUser(), [
        'title' => 'Fact sheet',
        'kind' => 'FACT_SHEET',
        'file' => materialBytes('s.pdf', pdfBytes(), 'application/pdf'),
    ])->json('id');

    $material = SalesMaterial::query()->findOrFail($id);
    $path = $material->file_path;

    $this->artisan('iconic:retention')->assertSuccessful();

    $material->refresh();

    expect($material->purged_at)->toBeNull()
        ->and($material->file_path)->toBe($path)
        ->and(Storage::disk('materials')->exists((string) $path))->toBeTrue();
});

test('triggers refuse deleting a material or changing its title', function (): void {
    $material = SalesMaterial::factory()->create();

    expect(fn () => DB::table('sales_materials')->where('id', $material->id)->update(['title' => 'Renamed']))
        ->toThrow(QueryException::class, 'immutable');

    expect(fn () => DB::table('sales_materials')->where('id', $material->id)->delete())
        ->toThrow(QueryException::class, 'cannot be deleted');

    DB::table('sales_materials')->where('id', $material->id)->update(['published' => false]);

    expect($material->fresh()?->published)->toBeFalse();
});
