<?php

namespace App\Events\Document;

use App\Events\TransactionalDomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentVersionCreated extends TransactionalDomainActivityEvent
{
    public function __construct(
        public readonly Document $document,
        User $actor,
        public readonly int $versionNumber,
    ) {
        parent::__construct(
            action: 'document.version_created',
            user: $actor,
            subject: $document,
            description: "Nouvelle version #{$versionNumber} : {$document->reference}",
        );
    }
}
