<?php

use App\Models\Document;
use App\Models\Folder;
use App\Models\Version;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function versionFolder(): Folder
{
    return Folder::query()->create([
        'name' => 'Versions',
        'created_by' => adminUser()->id,
    ]);
}

test('création d une nouvelle version verrouille la version précédente', function () {
    Sanctum::actingAs(adminUser());
    $folder = versionFolder();

    $created = $this->post('/api/documents', [
        'title' => 'Doc versions',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('v1.txt', "ligne A\n"),
    ], ['Accept' => 'application/json'])->json('document');

    $v1Id = $created['current_version']['id'];
    expect($created['current_version']['is_locked'])->toBeFalse();

    $this->post("/api/documents/{$created['id']}/versions", [
        'file' => UploadedFile::fake()->createWithContent('v2.txt', "ligne B\n"),
        'change_summary' => 'maj',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('document.current_version.version_number', 2)
        ->assertJsonPath('document.current_version.is_locked', false);

    expect(Version::query()->find($v1Id)->is_locked)->toBeTrue();
});

test('comparaison de versions texte renvoie métadonnées et diff', function () {
    Sanctum::actingAs(adminUser());
    $folder = versionFolder();

    $created = $this->post('/api/documents', [
        'title' => 'Compare me',
        'folder_id' => $folder->id,
        'is_editable' => true,
        'file' => UploadedFile::fake()->createWithContent('a.txt', "alpha\nshared\n"),
    ], ['Accept' => 'application/json'])->json('document');

    $this->putJson("/api/documents/{$created['id']}/content", [
        'content' => "beta\nshared\n",
        'change_summary' => 'édition',
    ])->assertOk();

    $document = Document::query()->with('versions')->findOrFail($created['id']);
    $left = $document->versions->firstWhere('version_number', 1);
    $right = $document->versions->firstWhere('version_number', 2);

    $response = $this->getJson(
        "/api/documents/{$document->id}/versions/compare?left_version_id={$left->id}&right_version_id={$right->id}"
    )->assertOk()
        ->assertJsonPath('content_comparable', true)
        ->assertJsonPath('content_identical', false)
        ->assertJsonPath('left.version_number', 1)
        ->assertJsonPath('right.version_number', 2);

    $types = collect($response->json('content_diff'))->pluck('type')->unique()->values()->all();
    expect($types)->toContain('remove')->toContain('add');
    expect($response->json('metadata_diff'))->toHaveKey('checksum');
});

test('comparaison binaire se limite aux métadonnées', function () {
    Sanctum::actingAs(adminUser());
    $folder = versionFolder();

    $created = $this->post('/api/documents', [
        'title' => 'PDF compare',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->json('document');

    $this->post("/api/documents/{$created['id']}/versions", [
        'file' => UploadedFile::fake()->create('b.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $document = Document::query()->with('versions')->findOrFail($created['id']);
    $left = $document->versions->firstWhere('version_number', 1);
    $right = $document->versions->firstWhere('version_number', 2);

    $this->getJson(
        "/api/documents/{$document->id}/versions/compare?left_version_id={$left->id}&right_version_id={$right->id}"
    )->assertOk()
        ->assertJsonPath('content_comparable', false)
        ->assertJsonPath('content_diff', null);
});
