<?php

namespace App\Services;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts site changes to the public Telegram group. Hooked into the models
 * (see AppServiceProvider) so it fires the same whether a change was made on
 * the website or through the bot.
 */
class Announcer
{
    public function incidentCreated(Incident $i): void
    {
        $this->post($this->incidentText($i, $i->status === 'warning' ? '🔴 <b>WARNING</b>' : ($i->status === 'resolved' ? '✅ <b>RESOLVED</b>' : '🟡 <b>MONITORING</b>')));
    }

    public function incidentStatusChanged(Incident $i): void
    {
        $head = match ($i->status) {
            'resolved' => '✅ <b>RESOLVED</b>',
            'warning' => '🔴 <b>UPDATE – now a WARNING</b>',
            default => '🟡 <b>UPDATE – now MONITORING</b>',
        };

        // Keep the resolved message short; people already saw the details.
        $this->post($i->status === 'resolved' ? "{$head}\n".e($i->title) : $this->incidentText($i, $head));
    }

    public function levelChanged(): void
    {
        $level = AlertLevel::find((int) Setting::get('alert_level', '0'));
        if ($level) {
            $this->post('🚦 <b>Safety alert level: '.e($level->name)."</b>\n".e($level->description));
        }
    }

    public function newsAdded(NewsItem $n): void
    {
        $text = '📰 <b>New SAPS release</b>'."\n".e($n->title);
        if ($n->source_url && preg_match('#^https?://#i', $n->source_url)) {
            $text .= "\n".e($n->source_url);
        }
        $this->post($text."\n\nAll releases: ".e(url('/news')));
    }

    private function incidentText(Incident $i, string $head): string
    {
        $text = "{$head}\n<b>".e($i->title).'</b>';
        if ($i->body) {
            $text .= "\n".e(mb_strimwidth($i->body, 0, 700, '…'));
        }

        return $text."\n\n".e(url('/'));
    }

    /** Sent after the response so a slow/unavailable Telegram never delays or breaks the site. */
    private function post(string $html): void
    {
        $chat = Setting::get('telegram_group_chat_id');
        if (! $chat || ! config('services.telegram.token')) {
            return;
        }

        dispatch(function () use ($chat, $html) {
            try {
                app(TelegramBot::class)->send($chat, $html);
            } catch (Throwable $e) {
                Log::warning('Group announcement failed', ['message' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
