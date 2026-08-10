<?php

namespace App\Events\Auth;

use App\Events\DomainActivityEvent;
use App\Models\User;

class UserLoggedIn extends DomainActivityEvent
{
    public function __construct(User $user)
    {
        parent::__construct(
            action: 'auth.login',
            user: $user,
            description: "Connexion de {$user->email}",
        );
    }
}
