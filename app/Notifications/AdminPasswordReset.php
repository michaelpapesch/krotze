<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The one e-mail this application ever sends. Everything else about Krotze is
 * deliberately account-free; the admin panel is the exception, and a locked-out
 * operator would otherwise have to edit the database by hand.
 */
class AdminPasswordReset extends Notification
{
    public function __construct(private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = Config::get('auth.passwords.'.Config::get('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Krotze admin password')
            ->greeting('Reset your admin password')
            ->line('Somebody asked to reset the password for the Krotze admin panel at '.config('app.url').'.')
            ->action('Choose a new password', route('admin.password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]))
            ->line("This link stops working in {$minutes} minutes, and can only be used once.")
            // Said plainly, because it is the question somebody who did not make
            // the request will actually have.
            ->line('If that was not you, nothing has happened yet — the password only changes once the link is opened and a new one is set. You can ignore this message.')
            ->salutation('— Krotze');
    }
}
