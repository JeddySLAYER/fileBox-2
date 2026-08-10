<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// ponytail: sync send — temp password must leave immediately; queue worker often absent in local
class TemporaryPasswordNotification extends Notification
{
    public function __construct(
        private readonly string $temporaryPassword,
        private readonly string $reason = 'created',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = rtrim((string) config('app.frontend_url'), '/').'/login';
        $isReset = $this->reason === 'reset';

        return (new MailMessage)
            ->subject($isReset
                ? 'FileBox — nouveau mot de passe temporaire'
                : 'Bienvenue sur FileBox — votre accès')
            ->view('emails.temporary-password', [
                'user' => $notifiable,
                'temporaryPassword' => $this->temporaryPassword,
                'reason' => $this->reason,
                'loginUrl' => $loginUrl,
            ]);
    }
}
