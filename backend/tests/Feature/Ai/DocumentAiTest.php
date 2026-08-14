<?php

use App\Models\Folder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
    config([
        'services.gemini.api_key' => 'test-key',
        'services.gemini.model' => 'gemini-2.0-flash',
        'services.gemini.image_model' => 'gemini-2.5-flash-image',
        'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ]);
});

function fakeGemini(string $text = 'Résumé de test'): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => $text]],
                    ],
                ],
            ],
        ], 200),
    ]);
}

function fakeGeminiImage(string $binary = 'FAKEPNGDATA'): void
{
    Http::fake([
        '*gemini-2.5-flash-image*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => base64_encode($binary),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);
}

function uploadScan(?Folder $folder = null): int
{
    $folder ??= Folder::query()->create(['name' => 'OCR', 'created_by' => adminUser()->id]);

    $id = test()->post('/api/documents', [
        'title' => 'Scan',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('scan.png', str_repeat('PNGDATA', 200)),
    ], ['Accept' => 'application/json'])->json('document.id');

    \App\Models\Version::query()->where('document_id', $id)->update([
        'mime_type' => 'image/png',
        'extension' => 'png',
    ]);

    return $id;
}

test('résumé IA d un document texte', function () {
    fakeGemini('Voici le résumé.');
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'AI', 'created_by' => adminUser()->id]);

    $id = $this->post('/api/documents', [
        'title' => 'Note',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'Long texte à résumer pour la GED.'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->postJson("/api/documents/{$id}/ai/summarize")
        ->assertOk()
        ->assertJsonPath('document.summary', 'Voici le résumé.');
});

test('ocr IA enregistre une nouvelle version du même document', function () {
    fakeGemini('Texte OCR extrait');
    Sanctum::actingAs(adminUser());
    $id = uploadScan();

    $this->postJson("/api/documents/{$id}/ai/ocr")
        ->assertOk()
        ->assertJsonPath('ocr_text', 'Texte OCR extrait');

    $saved = $this->postJson("/api/documents/{$id}/ai/ocr/save", [
        'text' => 'Texte OCR extrait',
    ])
        ->assertOk()
        ->json('document');

    expect($saved['id'])->toBe($id)
        ->and($saved['is_editable'])->toBeTrue()
        ->and($saved['current_version']['extension'])->toBe('txt')
        ->and($saved['current_version']['version_number'])->toBe(2);
});

test('eclaircissement IA d une image', function () {
    fakeGeminiImage('ECLAIRCI');
    Sanctum::actingAs(adminUser());
    $id = uploadScan();

    $this->postJson("/api/documents/{$id}/ai/enhance")
        ->assertOk()
        ->assertJsonPath('mime_type', 'image/png')
        ->assertJsonPath('file_name', 'scan-eclairci.png');

    $payload = $this->postJson("/api/documents/{$id}/ai/enhance")->json();
    expect(base64_decode($payload['image_base64']))->toBe('ECLAIRCI');
});

test('aperçu IA sur un fichier non stocké', function () {
    fakeGemini('Texte brut OCR');
    Sanctum::actingAs(adminUser());

    $this->post('/api/documents/ai/preview', [
        'action' => 'ocr',
        'file' => UploadedFile::fake()->createWithContent('scan.png', str_repeat('PNGDATA', 80)),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('action', 'ocr')
        ->assertJsonPath('ocr_text', 'Texte brut OCR');
});

test('aperçu éclaircissement sur un fichier non stocké', function () {
    fakeGeminiImage('PREVIEWIMG');
    Sanctum::actingAs(adminUser());

    $this->post('/api/documents/ai/preview', [
        'action' => 'enhance',
        'file' => UploadedFile::fake()->createWithContent('scan.png', str_repeat('PNGDATA', 80)),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('action', 'enhance')
        ->assertJsonPath('mime_type', 'image/png');
});

test('aperçu analyse sur un fichier texte non stocké', function () {
    fakeGemini('Fiche IA de test');
    Sanctum::actingAs(adminUser());

    $this->post('/api/documents/ai/preview', [
        'action' => 'analyze',
        'file' => UploadedFile::fake()->createWithContent('note.txt', 'Compte-rendu de réunion du 12 mars.'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('action', 'analyze')
        ->assertJsonPath('summary', 'Fiche IA de test');
});

test('sans clé gemini l api refuse clairement', function () {
    config(['services.gemini.api_key' => '']);
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'X', 'created_by' => adminUser()->id]);

    $id = $this->post('/api/documents', [
        'title' => 'Note',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('a.txt', 'abc'),
    ], ['Accept' => 'application/json'])->json('document.id');

    $this->postJson("/api/documents/{$id}/ai/summarize")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ai']);
});
