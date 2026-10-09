<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {--remove : Remove the webhook instead}';

    protected $description = 'Register (or remove) the Telegram webhook for this site';

    public function handle(TelegramBot $bot): int
    {
        if (! config('services.telegram.token') || ! config('services.telegram.secret')) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET in .env first.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $this->line(json_encode($bot->api('deleteWebhook')));

            return self::SUCCESS;
        }

        $url = rtrim(config('app.url'), '/').'/telegram/webhook/'.config('services.telegram.secret');
        $result = $bot->api('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.secret'),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);

        $bot->api('setMyCommands', ['commands' => [
            ['command' => 'status', 'description' => 'Current level and open items'],
            ['command' => 'level', 'description' => 'Change the safety alert level'],
            ['command' => 'new', 'description' => 'Post a warning or monitoring item'],
            ['command' => 'list', 'description' => 'Open items (resolve / delete)'],
            ['command' => 'sitrep', 'description' => 'Situation report entries'],
            ['command' => 'news', 'description' => 'Add a SAPS release'],
            ['command' => 'help', 'description' => 'All commands'],
        ]]);

        $this->line(json_encode($result));
        $this->info('Webhook set to '.preg_replace('#/webhook/.+$#', '/webhook/***', $url));

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
