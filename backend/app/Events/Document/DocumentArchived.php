<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;

class DocumentArchived extends DomainActivityEvent
{
    public function __construct(Document $document)
    {
        parent::__construct(
            action: 'document.archived',
            subject: $document,
            description: "Document archivé : {$document->reference}",
        );
    }
}
