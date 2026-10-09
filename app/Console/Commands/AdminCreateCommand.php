<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AdminCreateCommand extends Command
{
    protected $signature = 'admin:create {email} {name} {--password= : Leave out to generate one}';

    protected $description = 'Create (or reset the password of) an admin user';

    public function handle(): int
    {
        $password = $this->option('password') ?: bin2hex(random_bytes(8));

        $user = User::firstOrNew(['email' => $this->argument('email')]);
        $user->name = $this->argument('name');
        $user->password = $password;
        $user->save();

        $this->info("Admin ready: {$user->email}");
        if (! $this->option('password')) {
            $this->line("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
