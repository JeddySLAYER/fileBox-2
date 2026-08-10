<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;

class DocumentDeleted extends DomainActivityEvent
{
    public function __construct(
        Document $document,
        public readonly string $reference,
    ) {
        parent::__construct(
            action: 'document.deleted',
            subject: $document,
            description: "Document mis en corbeille : {$reference}",
        );
    }
}
