<?php

namespace App\Events\Document;

use App\Events\TransactionalDomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentContentSaved extends TransactionalDomainActivityEvent
{
    public function __construct(
        public readonly Document $document,
        User $actor,
        public readonly int $versionNumber,
    ) {
        parent::__construct(
            action: 'document.content_saved',
            user: $actor,
            subject: $document,
            description: "Contenu édité en ligne : {$document->reference} (v{$versionNumber})",
        );
    }
}
