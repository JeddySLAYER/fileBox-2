<?php

namespace App\Events\Backup;

use App\Events\DomainActivityEvent;
use App\Models\Backup;
use App\Models\User;

class BackupRestored extends DomainActivityEvent
{
    public function __construct(Backup $backup, User $actor)
    {
        parent::__construct(
            action: 'backup.restored',
            user: $actor,
            subject: $backup,
            description: "Sauvegarde restaurée : {$backup->name}",
        );
    }
}
