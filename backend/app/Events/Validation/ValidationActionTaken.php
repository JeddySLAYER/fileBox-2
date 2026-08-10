<?php

namespace App\Events\Validation;

use App\Events\TransactionalDomainActivityEvent;
use App\Models\Document;
use App\Models\User;
use App\Models\Validation;

class ValidationActionTaken extends TransactionalDomainActivityEvent
{
    public function __construct(
        string $activityAction,
        public readonly Document $document,
        public readonly Validation $validation,
        public readonly string $notificationAction,
        ?User $actor = null,
        public readonly ?int $excludeUserId = null,
        ?string $description = null,
        array $properties = [],
    ) {
        parent::__construct(
            action: $activityAction,
            user: $actor,
            subject: $document,
            description: $description ?? "Mise à jour validation sur {$document->reference}",
            properties: $properties,
        );
    }
}
