<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends LaravelResetPassword
{
    /**
     * Get the reset password notification mail message for the given reset token.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $resetUrl = $frontendUrl . '/auth/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage())
            ->subject('Reset Password Notification')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $resetUrl)
            ->line('This password reset link will expire in ' . config('auth.passwords.' . config('auth.defaults.passwords') . '.expire') . ' minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
