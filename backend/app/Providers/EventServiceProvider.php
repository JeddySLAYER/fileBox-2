<?php

namespace App\Providers;

use App\Events\Access\AccessGranted;
use App\Events\Access\AccessRevoked;
use App\Events\Auth\UserLoginFailed;
use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserLoggedOut;
use App\Events\Backup\BackupCreated;
use App\Events\Backup\BackupRestored;
use App\Events\Comment\CommentPosted;
use App\Events\Document\DocumentArchived;
use App\Events\Document\DocumentContentSaved;
use App\Events\Document\DocumentCreated;
use App\Events\Document\DocumentDeleted;
use App\Events\Document\DocumentProposed;
use App\Events\Document\DocumentPublished;
use App\Events\Document\DocumentRestored;
use App\Events\Document\DocumentUnarchived;
use App\Events\Document\DocumentVersionCreated;
use App\Events\Folder\FolderCreated;
use App\Events\Folder\FolderDeleted;
use App\Events\Settings\SettingsBulkUpdated;
use App\Events\Settings\SettingsUpdated;
use App\Events\Validation\ValidationActionTaken;
use App\Listeners\Access\NotifyAccessGranted;
use App\Listeners\Access\NotifyAccessRevoked;
use App\Listeners\Comment\NotifyCommentParticipants;
use App\Listeners\Document\NotifyDocumentProposed;
use App\Listeners\Document\NotifyDocumentPublished;
use App\Listeners\Document\PrepareDocumentForIndexing;
use App\Listeners\RecordActivityLog;
use App\Listeners\Validation\NotifyValidationStakeholders;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected $listen = [
        UserLoggedIn::class => [RecordActivityLog::class],
        UserLoggedOut::class => [RecordActivityLog::class],
        UserLoginFailed::class => [RecordActivityLog::class],

        DocumentCreated::class => [
            RecordActivityLog::class,
            PrepareDocumentForIndexing::class,
        ],
        DocumentVersionCreated::class => [RecordActivityLog::class],
        DocumentContentSaved::class => [RecordActivityLog::class],
        DocumentArchived::class => [RecordActivityLog::class],
        DocumentUnarchived::class => [RecordActivityLog::class],
        DocumentDeleted::class => [RecordActivityLog::class],
        DocumentPublished::class => [
            RecordActivityLog::class,
            NotifyDocumentPublished::class,
        ],
        DocumentProposed::class => [
            RecordActivityLog::class,
            NotifyDocumentProposed::class,
        ],
        DocumentRestored::class => [RecordActivityLog::class],

        FolderCreated::class => [RecordActivityLog::class],
        FolderDeleted::class => [RecordActivityLog::class],

        AccessGranted::class => [
            RecordActivityLog::class,
            NotifyAccessGranted::class,
        ],
        AccessRevoked::class => [
            RecordActivityLog::class,
            NotifyAccessRevoked::class,
        ],

        ValidationActionTaken::class => [
            RecordActivityLog::class,
            NotifyValidationStakeholders::class,
        ],

        CommentPosted::class => [
            RecordActivityLog::class,
            NotifyCommentParticipants::class,
        ],

        BackupCreated::class => [RecordActivityLog::class],
        BackupRestored::class => [RecordActivityLog::class],

        SettingsUpdated::class => [RecordActivityLog::class],
        SettingsBulkUpdated::class => [RecordActivityLog::class],
    ];
}
