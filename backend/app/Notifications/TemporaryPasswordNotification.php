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

        $message = (new MailMessage)
            ->subject($isReset
                ? 'FileBox — nouveau mot de passe temporaire'
                : 'Bienvenue sur FileBox — votre mot de passe temporaire')
            ->greeting('Bonjour '.$notifiable->name.',');

        if ($isReset) {
            $message->line('Votre mot de passe FileBox a été réinitialisé par un administrateur.');
        } else {
            $message->line('Un compte FileBox a été créé pour vous.');
        }

        return $message
            ->line('Identifiant : '.$notifiable->email)
            ->line('Mot de passe temporaire : '.$this->temporaryPassword)
            ->line('Vous devrez changer ce mot de passe lors de votre première connexion.')
            ->action('Se connecter', $loginUrl)
            ->salutation('L\'équipe FileBox');
    }
}
