<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $password) {}

    public function build(): static
    {
        return $this->subject('Your Password Has Been Reset')
                    ->view('emails.password-reset');
    }
}
