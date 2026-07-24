<?php

use App\Models\DocumentType;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('un admin peut gérer les types de documents', function () {
    Sanctum::actingAs(adminUser());

    $created = $this->postJson('/api/document-types', [
        'name' => 'Contrat',
        'description' => 'Contrats commerciaux',
    ])->assertCreated()
        ->assertJsonPath('document_type.slug', 'contrat')
        ->json('document_type');

    $this->getJson('/api/document-types')->assertOk();

    $this->putJson("/api/document-types/{$created['id']}", [
        'name' => 'Contrat cadre',
    ])->assertOk()
        ->assertJsonPath('document_type.name', 'Contrat cadre');

    $this->deleteJson("/api/document-types/{$created['id']}")->assertOk();
    $archived = DocumentType::withTrashed()->find($created['id']);
    expect($archived->trashed())->toBeTrue()
        ->and($archived->slug)->not->toBe('contrat');

    $this->postJson('/api/document-types', [
        'name' => 'Contrat',
    ])->assertCreated()
        ->assertJsonPath('document_type.slug', 'contrat');
});
