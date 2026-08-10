<?php

use App\Http\Controllers\Api\Access\AccessController;
use App\Http\Controllers\Api\ActivityLog\ActivityLogController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Backup\BackupController;
use App\Http\Controllers\Api\Comment\CommentController;
use App\Http\Controllers\Api\Dashboard\DashboardController;
use App\Http\Controllers\Api\Department\DepartmentController;
use App\Http\Controllers\Api\Document\DocumentAiController;
use App\Http\Controllers\Api\Document\DocumentController;
use App\Http\Controllers\Api\DocumentType\DocumentTypeController;
use App\Http\Controllers\Api\Favorite\FavoriteController;
use App\Http\Controllers\Api\Folder\FolderController;
use App\Http\Controllers\Api\Notification\NotificationController;
use App\Http\Controllers\Api\Permission\PermissionController;
use App\Http\Controllers\Api\Project\ProjectController;
use App\Http\Controllers\Api\Role\RoleController;
use App\Http\Controllers\Api\Search\SearchController;
use App\Http\Controllers\Api\Setting\SettingController;
use App\Http\Controllers\Api\Tag\TagController;
use App\Http\Controllers\Api\User\UserController;
use App\Http\Controllers\Api\Validation\ValidationController;
use App\Http\Controllers\Api\Workflow\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/password', [AuthController::class, 'changePassword']);
    });
});

// URL signée temporaire (pas d'auth Bearer — signature HMAC)
Route::get('signed/documents/{document}/preview', [DocumentController::class, 'signedPreview'])
    ->name('documents.preview.signed')
    ->middleware('signed');

Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {
    // Utilisateurs
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

    // Rôles
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::get('roles/{role}', [RoleController::class, 'show']);
    Route::put('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

    // Permissions
    Route::get('permissions', [PermissionController::class, 'index']);
    Route::post('permissions', [PermissionController::class, 'store']);
    Route::get('permissions/{permission}', [PermissionController::class, 'show']);
    Route::put('permissions/{permission}', [PermissionController::class, 'update']);
    Route::delete('permissions/{permission}', [PermissionController::class, 'destroy']);

    // Départements
    Route::get('departments', [DepartmentController::class, 'index']);
    Route::post('departments', [DepartmentController::class, 'store']);
    Route::get('departments/{department}', [DepartmentController::class, 'show']);
    Route::put('departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);

    // Projets
    Route::get('projects', [ProjectController::class, 'index']);
    Route::post('projects', [ProjectController::class, 'store']);
    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::put('projects/{project}', [ProjectController::class, 'update']);
    Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    Route::put('projects/{project}/members', [ProjectController::class, 'syncMembers']);
    Route::get('projects/{project}/member-candidates', [ProjectController::class, 'memberCandidates']);

    // Dossiers
    Route::get('folders', [FolderController::class, 'index']);
    Route::get('folders/tree', [FolderController::class, 'tree']);
    Route::post('folders', [FolderController::class, 'store']);
    Route::get('folders/{folder}', [FolderController::class, 'show']);
    Route::put('folders/{folder}', [FolderController::class, 'update']);
    Route::put('folders/{folder}/move', [FolderController::class, 'move']);
    Route::delete('folders/{folder}', [FolderController::class, 'destroy']);
    Route::post('folders/{id}/restore', [FolderController::class, 'restore']);

    // Types de documents
    Route::get('document-types', [DocumentTypeController::class, 'index']);
    Route::post('document-types', [DocumentTypeController::class, 'store']);
    Route::get('document-types/{document_type}', [DocumentTypeController::class, 'show']);
    Route::put('document-types/{document_type}', [DocumentTypeController::class, 'update']);
    Route::delete('document-types/{document_type}', [DocumentTypeController::class, 'destroy']);

    // Tags
    Route::get('tags', [TagController::class, 'index']);
    Route::post('tags', [TagController::class, 'store']);
    Route::get('tags/{tag}', [TagController::class, 'show']);
    Route::put('tags/{tag}', [TagController::class, 'update']);
    Route::delete('tags/{tag}', [TagController::class, 'destroy']);
    Route::put('documents/{document}/tags', [TagController::class, 'syncDocumentTags']);

    // Documents
    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store']);
    Route::get('documents/{document}', [DocumentController::class, 'show']);
    Route::put('documents/{document}', [DocumentController::class, 'update']);
    Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
    Route::post('documents/{id}/restore', [DocumentController::class, 'restore']);
    Route::put('documents/{document}/move', [DocumentController::class, 'move']);
    Route::post('documents/{document}/archive', [DocumentController::class, 'archive']);
    Route::post('documents/{document}/unarchive', [DocumentController::class, 'unarchive']);
    Route::post('documents/{document}/publish', [DocumentController::class, 'publish']);
    Route::post('documents/{document}/propose', [DocumentController::class, 'propose']);
    Route::get('documents/{document}/content', [DocumentController::class, 'content']);
    Route::put('documents/{document}/content', [DocumentController::class, 'saveContent']);
    Route::get('documents/{document}/versions', [DocumentController::class, 'versions']);
    Route::get('documents/{document}/versions/compare', [DocumentController::class, 'compareVersions']);
    Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion']);
    Route::get('documents/{document}/download', [DocumentController::class, 'download']);
    Route::get('documents/{document}/versions/{version}/download', [DocumentController::class, 'downloadVersion']);
    Route::get('documents/{document}/preview', [DocumentController::class, 'preview']);
    Route::get('documents/{document}/versions/{version}/preview', [DocumentController::class, 'previewVersion']);
    Route::get('documents/{document}/preview-url', [DocumentController::class, 'previewUrl']);

    // IA (Gemini) — résumé / analyse / OCR
    Route::post('documents/{document}/ai/summarize', [DocumentAiController::class, 'summarize']);
    Route::post('documents/{document}/ai/analyze', [DocumentAiController::class, 'analyze']);
    Route::post('documents/{document}/ai/ocr', [DocumentAiController::class, 'ocr']);

    // Commentaires (liés au document — RG-DOC-015)
    Route::get('documents/{document}/comments', [CommentController::class, 'index']);
    Route::post('documents/{document}/comments', [CommentController::class, 'store']);
    Route::put('comments/{comment}', [CommentController::class, 'update']);
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

    // Workflows
    Route::get('workflows', [WorkflowController::class, 'index']);
    Route::post('workflows', [WorkflowController::class, 'store']);
    Route::get('workflows/{workflow}', [WorkflowController::class, 'show']);
    Route::put('workflows/{workflow}', [WorkflowController::class, 'update']);
    Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy']);

    // Validations
    Route::get('documents/{document}/validations', [ValidationController::class, 'index']);
    Route::post('documents/{document}/workflow/start', [ValidationController::class, 'start']);
    Route::post('documents/{document}/workflow/restart', [ValidationController::class, 'restart']);
    Route::post('validations/{validation}/approve', [ValidationController::class, 'approve']);
    Route::post('validations/{validation}/reject', [ValidationController::class, 'reject']);
    Route::post('validations/{validation}/request-correction', [ValidationController::class, 'requestCorrection']);

    // Favoris
    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::post('documents/{document}/favorite', [FavoriteController::class, 'storeDocument']);
    Route::delete('documents/{document}/favorite', [FavoriteController::class, 'destroyDocument']);
    Route::post('folders/{folder}/favorite', [FavoriteController::class, 'storeFolder']);
    Route::delete('folders/{folder}/favorite', [FavoriteController::class, 'destroyFolder']);

    // Accès spécifiques
    Route::get('accesses/mine', [AccessController::class, 'mine']);
    Route::get('documents/{document}/accesses', [AccessController::class, 'forDocument']);
    Route::post('documents/{document}/accesses', [AccessController::class, 'storeForDocument']);
    Route::get('folders/{folder}/accesses', [AccessController::class, 'forFolder']);
    Route::post('folders/{folder}/accesses', [AccessController::class, 'storeForFolder']);
    Route::put('accesses/{access}', [AccessController::class, 'update']);
    Route::delete('accesses/{access}', [AccessController::class, 'destroy']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // Recherche
    Route::get('search', SearchController::class);

    // Tableau de bord
    Route::get('dashboard', DashboardController::class);

    // Paramètres système
    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings/bulk', [SettingController::class, 'bulk']);
    Route::put('settings', [SettingController::class, 'upsert']);
    Route::get('settings/{key}', [SettingController::class, 'show']);

    // Sauvegardes
    Route::get('backups', [BackupController::class, 'index']);
    Route::post('backups', [BackupController::class, 'store']);
    Route::get('backups/{backup}/download', [BackupController::class, 'download']);
    Route::post('backups/{backup}/restore', [BackupController::class, 'restore']);
    Route::delete('backups/{backup}', [BackupController::class, 'destroy']);

    // Journalisation
    Route::get('activity-logs', [ActivityLogController::class, 'index']);
});
