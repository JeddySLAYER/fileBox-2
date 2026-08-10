<?php

namespace App\Events\Access;

use App\Events\DomainActivityEvent;
use App\Models\Access;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AccessGranted extends DomainActivityEvent
{
    public function __construct(
        public readonly Access $access,
        User $grantor,
        Model $accessible,
    ) {
        parent::__construct(
            action: 'access.granted',
            user: $grantor,
            subject: $access,
            description: "Accès accordé à {$access->user->email} sur {$accessible->getMorphClass()}#{$accessible->getKey()}",
            properties: ['abilities' => $access->abilities, 'user_id' => $access->user_id],
        );
    }
}
