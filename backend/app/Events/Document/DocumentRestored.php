<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;

class DocumentRestored extends DomainActivityEvent
{
    public function __construct(Document $document)
    {
        parent::__construct(
            action: 'document.restored',
            subject: $document,
            description: "Document restauré : {$document->reference}",
        );
    }
}
