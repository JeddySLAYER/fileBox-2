<?php

use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Notifications\AccessGrantedNotification;
use Database\Seeders\RolePermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

test('api notifications liste et marque comme lue', function () {
    $admin = adminUser();
    $guest = User::factory()->create([
        'must_change_password' => false,
        'is_active' => true,
    ]);

    $folder = Folder::query()->create(['name' => 'N2', 'created_by' => $admin->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-NOTIF-000001',
        'title' => 'Doc',
        'folder_id' => $folder->id,
        'author_id' => $admin->id,
        'owner_id' => $admin->id,
        'is_editable' => false,
    ]);

    $access = Access::query()->create([
        'user_id' => $guest->id,
        'accessible_type' => 'document',
        'accessible_id' => $document->id,
        'abilities' => ['view'],
        'granted_by' => $admin->id,
    ]);

    $guest->notifyNow(new AccessGrantedNotification($access->load(['accessible', 'grantor'])));

    Sanctum::actingAs($guest);

    $this->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 1);

    $list = $this->getJson('/api/notifications')->assertOk();
    $id = $list->json('data.0.id');

    $this->postJson("/api/notifications/{$id}/read")->assertOk();

    $this->getJson('/api/notifications/unread-count')
        ->assertJsonPath('unread_count', 0);
});

test('mark all as read vide les non lues', function () {
    $admin = adminUser();
    $guest = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
    $folder = Folder::query()->create(['name' => 'N3', 'created_by' => $admin->id]);
    $document = Document::query()->create([
        'reference' => 'DOC-NOTIF-000002',
        'title' => 'Doc 2',
        'folder_id' => $folder->id,
        'author_id' => $admin->id,
        'owner_id' => $admin->id,
        'is_editable' => false,
    ]);

    foreach (range(1, 3) as $i) {
        $access = Access::query()->create([
            'user_id' => $guest->id,
            'accessible_type' => 'folder',
            'accessible_id' => $folder->id,
            'abilities' => ['view'],
            'granted_by' => $admin->id,
        ]);
        $guest->notifyNow(new AccessGrantedNotification($access->load('accessible')));
        $access->delete();
    }

    Sanctum::actingAs($guest);

    expect($guest->unreadNotifications()->count())->toBe(3);

    $this->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('marked', 3);

    expect($guest->fresh()->unreadNotifications()->count())->toBe(0);
});
