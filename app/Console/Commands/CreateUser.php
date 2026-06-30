<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    protected $signature = 'user:create
                            {email    : Email address}
                            {password : Password (min 8 chars)}
                            {name?    : Display name (optional)}';

    protected $description = 'Create a user for testing/seeding';

    public function handle(): int
    {
        $email    = $this->argument('email');
        $password = $this->argument('password');
        $name     = $this->argument('name') ?? explode('@', $email)[0];

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->warn("User {$email} already exists.");
            return self::FAILURE;
        }

        $user = User::create([
            'name'              => $name,
            'email'             => $email,
            'password'          => $password,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        $this->table(
            ['Field', 'Value'],
            [
                ['UUID',  $user->id],
                ['Email', $email],
                ['Name',  $name],
            ]
        );
        $this->line("  Login → Email: {$email}  /  Password: {$password}");

        return self::SUCCESS;
    }
}
