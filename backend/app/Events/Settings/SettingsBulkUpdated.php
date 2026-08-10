<?php

namespace App\Events\Settings;

use App\Events\DomainActivityEvent;
use App\Models\User;

class SettingsBulkUpdated extends DomainActivityEvent
{
    /** @param  list<string>  $keys */
    public function __construct(User $actor, array $keys)
    {
        parent::__construct(
            action: 'settings.bulk_updated',
            user: $actor,
            description: 'Mise à jour groupée des paramètres système',
            properties: ['keys' => $keys],
        );
    }
}
