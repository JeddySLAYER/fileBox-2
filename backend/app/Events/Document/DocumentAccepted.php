<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentAccepted extends DomainActivityEvent
{
    public function __construct(Document $document, User $actor)
    {
        parent::__construct(
            action: 'document.accepted',
            user: $actor,
            subject: $document,
            description: "Proposition acceptée : {$document->reference}",
        );
    }
}
