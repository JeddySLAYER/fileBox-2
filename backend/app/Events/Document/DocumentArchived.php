<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentArchived extends DomainActivityEvent
{
    public function __construct(Document $document, ?User $actor = null)
    {
        parent::__construct(
            action: 'document.archived',
            user: $actor ?? auth()->user(),
            subject: $document,
            description: "Document archivé : {$document->reference}",
        );
    }
}
