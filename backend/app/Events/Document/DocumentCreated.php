<?php

namespace App\Events\Document;

use App\Events\TransactionalDomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentCreated extends TransactionalDomainActivityEvent
{
    public function __construct(
        public readonly Document $document,
        User $actor,
    ) {
        parent::__construct(
            action: 'document.created',
            user: $actor,
            subject: $document,
            description: "Document créé : {$document->reference}",
        );
    }
}
