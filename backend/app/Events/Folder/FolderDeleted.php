<?php

namespace App\Events\Folder;

use App\Events\DomainActivityEvent;
use App\Models\Folder;

class FolderDeleted extends DomainActivityEvent
{
    public function __construct(Folder $folder, string $name)
    {
        parent::__construct(
            action: 'folder.deleted',
            subject: $folder,
            description: "Dossier supprimé : {$name}",
        );
    }
}
