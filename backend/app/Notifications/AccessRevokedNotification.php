<?php

namespace App\Notifications;

use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use Illuminate\Notifications\Notification;

class AccessRevokedNotification extends Notification
{
    public function __construct(
        private readonly Access $access,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $accessible = $this->access->accessible;
        $type = $accessible instanceof Document ? 'document' : 'folder';
        $label = $accessible instanceof Document
            ? ($accessible->title ?? 'Document')
            : ($accessible instanceof Folder ? $accessible->name : 'Ressource');

        return [
            'type' => 'access.revoked',
            'title' => 'Accès révoqué',
            'message' => "Votre accès sur « {$label} » a été révoqué.",
            'access_id' => $this->access->id,
            'accessible_type' => $type,
            'accessible_id' => $this->access->accessible_id,
        ];
    }
}
