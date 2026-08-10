<?php

namespace App\Events\Access;

use App\Events\DomainActivityEvent;
use App\Models\Access;

class AccessRevoked extends DomainActivityEvent
{
    public function __construct(
        public readonly Access $access,
    ) {
        parent::__construct(
            action: 'access.revoked',
            subject: $access,
            description: "Accès révoqué (user #{$access->user_id} sur {$access->accessible_type}#{$access->accessible_id})",
        );
    }
}
