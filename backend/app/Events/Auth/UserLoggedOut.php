<?php

namespace App\Events\Auth;

use App\Events\DomainActivityEvent;
use App\Models\User;

class UserLoggedOut extends DomainActivityEvent
{
    public function __construct(User $user)
    {
        parent::__construct(
            action: 'auth.logout',
            user: $user,
            description: "Déconnexion de {$user->email}",
        );
    }
}
