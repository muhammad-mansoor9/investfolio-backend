<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewAccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $password) {}

    public function build(): static
    {
        return $this->subject('Your New Account Has Been Created')
                    ->view('emails.new-account-created');
    }
}
