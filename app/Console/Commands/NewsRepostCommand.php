<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Models\Setting;
use App\Services\Announcer;
use App\Services\TelegramBot;
use Illuminate\Console\Command;

class NewsRepostCommand extends Command
{
    protected $signature = 'news:repost {id? : News item id; defaults to the latest release}';

    protected $description = 'Re-send a SAPS release to the Telegram group in the current format';

    public function handle(Announcer $announcer, TelegramBot $bot): int
    {
        $chat = Setting::get('telegram_group_chat_id');
        if (! $chat) {
            $this->error('No Telegram group is set (php artisan telegram:group @name).');

            return self::FAILURE;
        }

        $item = $this->argument('id')
            ? NewsItem::find($this->argument('id'))
            : NewsItem::orderByDesc('published_at')->orderByDesc('id')->first();

        if (! $item) {
            $this->error('No such release.');

            return self::FAILURE;
        }

        $messages = $announcer->newsMessages($item);
        foreach ($messages as $message) {
            $bot->send($chat, $message);
        }

        $this->info('Reposted "'.$item->title.'" ('.count($messages).' message'.(count($messages) === 1 ? '' : 's').').');

        return self::SUCCESS;
    }
}
