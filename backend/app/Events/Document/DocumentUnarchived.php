<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;

class DocumentUnarchived extends DomainActivityEvent
{
    public function __construct(Document $document)
    {
        parent::__construct(
            action: 'document.unarchived',
            subject: $document,
            description: "Document désarchivé : {$document->reference}",
        );
    }
}
