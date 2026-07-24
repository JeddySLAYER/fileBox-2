<?php

namespace App\Notifications;

use App\Models\Access;
use App\Models\Document;
use App\Models\Folder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AccessGrantedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            'type' => 'access.granted',
            'title' => 'Nouvel accès accordé',
            'message' => "Un accès vous a été accordé sur « {$label} ».",
            'access_id' => $this->access->id,
            'accessible_type' => $type,
            'accessible_id' => $this->access->accessible_id,
            'abilities' => $this->access->abilities,
            'starts_at' => $this->access->starts_at?->toIso8601String(),
            'ends_at' => $this->access->ends_at?->toIso8601String(),
            'granted_by' => $this->access->granted_by,
        ];
    }
}
