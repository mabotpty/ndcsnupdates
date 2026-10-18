<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushKeysCommand extends Command
{
    protected $signature = 'push:keys {--force : Replace existing keys (this invalidates every existing subscription)}';

    protected $description = 'Generate the VAPID keys used for browser push notifications and save them to .env';

    public function handle(): int
    {
        $env = base_path('.env');
        $contents = is_file($env) ? file_get_contents($env) : '';

        if (preg_match('/^VAPID_PRIVATE_KEY=.+/m', $contents) && ! $this->option('force')) {
            $this->warn('VAPID keys already exist in .env. Use --force to replace them (existing subscriptions would stop working).');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();

        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
            $contents = preg_match("/^{$name}=.*/m", $contents)
                ? preg_replace("/^{$name}=.*/m", "{$name}={$value}", $contents)
                : rtrim($contents)."\n{$name}={$value}\n";
        }
        file_put_contents($env, $contents);

        $this->info('VAPID keys written to .env. Run `php artisan config:cache` if config is cached.');

        return self::SUCCESS;
    }
}
