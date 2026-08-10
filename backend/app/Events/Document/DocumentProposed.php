<?php

namespace App\Events\Document;

use App\Events\DomainActivityEvent;
use App\Models\Document;
use App\Models\User;

class DocumentProposed extends DomainActivityEvent
{
    public function __construct(Document $document, User $actor)
    {
        parent::__construct(
            action: 'document.proposed',
            user: $actor,
            subject: $document,
            description: "Document proposé à validation : {$document->reference}",
            properties: [
                'workflow_id' => $document->workflow_id,
                'document_type_id' => $document->document_type_id,
            ],
        );
    }
}
