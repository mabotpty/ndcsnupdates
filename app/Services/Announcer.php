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
        $head = $i->status === 'warning' ? '🔴 <b>WARNING</b>' : ($i->status === 'resolved' ? '✅ <b>RESOLVED</b>' : '🟡 <b>MONITORING</b>');
        $this->post($this->incidentText($i, $head, $i->media ? 500 : 700), $i->media ?? []);
    }

    /** Photos/videos added to an existing incident (shared to the group only). */
    public function mediaAdded(Incident $i, array $media): void
    {
        $this->post('📷 <b>Imagery added</b>'."\n<b>".e($i->title)."</b>\n\n".e(url('/')), $media);
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

    private function incidentText(Incident $i, string $head, int $bodyLimit = 700): string
    {
        $text = "{$head}\n<b>".e($i->title).'</b>';
        if ($i->body) {
            $text .= "\n".e(mb_strimwidth($i->body, 0, $bodyLimit, '…'));
        }

        // Only mention the time when it was reported after the event (or backdated).
        if ($i->published_at && $i->published_at->lt(now()->subMinutes(10))) {
            $text .= "\n🕒 Occurred ".$i->published_at->format('D j M, H:i');
        }

        return $text."\n\n".e(url('/'));
    }

    /** Sent after the response so a slow/unavailable Telegram never delays or breaks the site. */
    private function post(string $html, array $media = []): void
    {
        $chat = Setting::get('telegram_group_chat_id');
        if (! $chat || ! config('services.telegram.token')) {
            return;
        }

        dispatch(function () use ($chat, $html, $media) {
            try {
                $bot = app(TelegramBot::class);
                $media ? $bot->sendMedia($chat, $media, $html) : $bot->send($chat, $html);
            } catch (Throwable $e) {
                Log::warning('Group announcement failed', ['message' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
