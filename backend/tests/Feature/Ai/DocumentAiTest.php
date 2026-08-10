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

test('ocr IA sur image', function () {
    fakeGemini('Texte OCR extrait');
    Sanctum::actingAs(adminUser());
    $folder = Folder::query()->create(['name' => 'OCR', 'created_by' => adminUser()->id]);

    $id = $this->post('/api/documents', [
        'title' => 'Scan',
        'folder_id' => $folder->id,
        'file' => UploadedFile::fake()->createWithContent('scan.png', str_repeat('PNGDATA', 200)),
    ], ['Accept' => 'application/json'])->json('document.id');

    // Force mime image pour le multimodal (fake upload = octet-stream parfois)
    \App\Models\Version::query()->where('document_id', $id)->update([
        'mime_type' => 'image/png',
        'extension' => 'png',
    ]);

    $this->postJson("/api/documents/{$id}/ai/ocr")
        ->assertOk()
        ->assertJsonPath('ocr_text', 'Texte OCR extrait');
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
