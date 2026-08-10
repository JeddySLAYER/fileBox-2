<?php

namespace App\Events\Folder;

use App\Events\DomainActivityEvent;
use App\Models\Folder;
use App\Models\User;

class FolderCreated extends DomainActivityEvent
{
    public function __construct(Folder $folder, User $actor)
    {
        parent::__construct(
            action: 'folder.created',
            user: $actor,
            subject: $folder,
            description: "Dossier créé : {$folder->name}",
        );
    }
}
