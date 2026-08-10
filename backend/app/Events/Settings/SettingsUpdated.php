<?php

namespace App\Events\Settings;

use App\Events\DomainActivityEvent;
use App\Models\User;

class SettingsUpdated extends DomainActivityEvent
{
    public function __construct(User $actor, string $key)
    {
        parent::__construct(
            action: 'settings.updated',
            user: $actor,
            description: "Paramètre mis à jour : {$key}",
            properties: ['key' => $key],
        );
    }
}
