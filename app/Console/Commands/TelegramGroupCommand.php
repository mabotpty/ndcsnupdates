<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\TelegramBot;
use Illuminate\Console\Command;

class TelegramGroupCommand extends Command
{
    protected $signature = 'telegram:group {chat? : @username of a public group, or a numeric chat id; omit to show the current one} {--clear : Stop posting to a group}';

    protected $description = 'Choose the Telegram group that site updates are announced in';

    public function handle(TelegramBot $bot): int
    {
        if ($this->option('clear')) {
            Setting::put('telegram_group_chat_id', null);
            $this->info('Group announcements switched off.');

            return self::SUCCESS;
        }

        if (! $this->argument('chat')) {
            $this->line('Current group chat id: '.(Setting::get('telegram_group_chat_id') ?: '(none)'));

            return self::SUCCESS;
        }

        $chat = $bot->api('getChat', ['chat_id' => $this->argument('chat')]);
        if (! ($chat['ok'] ?? false)) {
            $this->error('Telegram could not find that chat (is the bot a member?): '.($chat['description'] ?? 'no response'));

            return self::FAILURE;
        }

        Setting::put('telegram_group_chat_id', (string) $chat['result']['id']);
        $this->info('Announcing to "'.($chat['result']['title'] ?? '?').'" ('.$chat['result']['id'].')');

        return self::SUCCESS;
    }
}
