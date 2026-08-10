<?php

namespace App\Events\Backup;

use App\Events\DomainActivityEvent;
use App\Models\Backup;
use App\Models\User;

class BackupCreated extends DomainActivityEvent
{
    public function __construct(Backup $backup, User $actor)
    {
        parent::__construct(
            action: 'backup.created',
            user: $actor,
            subject: $backup,
            description: "Sauvegarde créée : {$backup->name}",
        );
    }
}
