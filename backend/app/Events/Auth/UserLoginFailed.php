<?php

namespace App\Events\Auth;

use App\Events\DomainActivityEvent;

class UserLoginFailed extends DomainActivityEvent
{
    public function __construct(
        string $email,
        string $reason,
        ?string $ip = null,
    ) {
        parent::__construct(
            action: 'auth.login_failed',
            description: "Échec de connexion pour {$email}",
            properties: [
                'email' => $email,
                'reason' => $reason,
                'ip' => $ip,
            ],
        );
    }
}
