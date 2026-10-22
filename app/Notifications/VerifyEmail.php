<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public const EXPIRES_MINUTES = 60;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Verify your Investfolio email address')
            ->line('Please confirm your email address to finish setting up your Investfolio account.')
            ->action('Verify email address', self::verificationUrl($notifiable))
            ->line('This link expires in ' . self::EXPIRES_MINUTES . ' minutes.')
            ->line('If you did not create an account, no further action is required.');
    }

    // Signed relative to the path so the signature survives a TLS-terminating proxy; APP_URL supplies the host.
    public static function verificationUrl(object $notifiable): string
    {
        return rtrim(config('app.url'), '/') . URL::temporarySignedRoute(
            'auth.email.verify',
            now()->addMinutes(self::EXPIRES_MINUTES),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->email)],
            false
        );
    }
}
