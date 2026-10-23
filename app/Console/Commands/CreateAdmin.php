<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Support\AdminModules;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create
                            {email : Email address used to sign in}
                            {--name= : Display name (defaults to the part before @)}';

    protected $description = 'Create an admin panel account with a full-access role';

    private const ROLE_NAME = 'Super admin';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        if (Admin::where('email', $email)->exists()) {
            $this->warn("An admin with {$email} already exists.");

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 10 characters)');
        if (strlen((string) $password) < 10) {
            $this->error('Password must be at least 10 characters.');

            return self::FAILURE;
        }
        if ($this->secret('Confirm password') !== $password) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $role = AdminRole::firstOrCreate(
            ['name' => self::ROLE_NAME],
            ['module_access' => AdminModules::ALL, 'status' => true],
        );

        $admin = Admin::create([
            'admin_role_id' => $role->id,
            'name' => $this->option('name') ?: explode('@', $email)[0],
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Admin {$admin->email} created with the \"{$role->name}\" role. Sign in at ".url('/admin'));

        return self::SUCCESS;
    }
}
