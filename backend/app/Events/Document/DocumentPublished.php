<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentPublished extends DomainActivityEvent
{
    public function __construct(Document $document, ?User $actor = null)
    {
        parent::__construct(
            action: 'document.published',
            user: $actor,
            subject: $document,
            description: "Document publié : {$document->reference}",
        );
    }
}
