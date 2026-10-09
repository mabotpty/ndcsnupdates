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
        // Resolved alerts are opt-in per incident (the person resolving chooses).
        if ($i->status === 'resolved' && ! $i->announce) {
            return;
        }

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
        $this->post($this->newsMessages($n));
    }

    /**
     * The Telegram messages for a release, in send order.
     *
     * @return list<string>
     */
    public function newsMessages(NewsItem $n): array
    {
        // Alert type, title, the whole release, its source, then our website.
        $head = "📰 <b>SAPS RELEASE</b>\n<b>".e($n->title).'</b>';

        $tail = '';
        if ($n->source_url && preg_match('#^https?://#i', $n->source_url)) {
            $tail .= "\n\nSource: ".e($n->source_url);
        }
        $tail .= "\n\nNDCSN Updates: ".e(url('/news'));

        return $this->messages($head, trim((string) $n->body), $tail);
    }

    /**
     * Telegram messages max out at 4096 characters, so a long release goes out as
     * several messages in order, with the source and website links on the last one.
     *
     * @return list<string>
     */
    private function messages(string $head, string $body, string $tail, int $limit = 3000): array
    {
        $pieces = $body === '' ? [''] : $this->splitText($body, $limit);

        $messages = [];
        foreach ($pieces as $n => $piece) {
            $text = ($n === 0 ? $head.($piece !== '' ? "\n\n" : '') : '').e($piece);
            if ($n === count($pieces) - 1) {
                $text .= $tail;
            }
            $messages[] = $text;
        }

        // HTML escaping can lengthen text; if anything is still too long, split finer.
        foreach ($messages as $m) {
            if (mb_strlen($m) > 4096 && $limit > 300) {
                return $this->messages($head, $body, $tail, intdiv($limit, 2));
            }
        }

        return $messages;
    }

    /** Split at paragraph, line, sentence or word boundaries, never mid-word. */
    private function splitText(string $text, int $limit): array
    {
        $parts = [];
        while (mb_strlen($text) > $limit) {
            $window = mb_substr($text, 0, $limit);
            $cut = 0;
            foreach (["\n\n", "\n", '. ', ' '] as $sep) {
                $pos = mb_strrpos($window, $sep);
                if ($pos !== false && $pos > $limit * 0.4) {
                    $cut = $pos + mb_strlen($sep);
                    break;
                }
            }
            $cut = $cut ?: $limit;
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        $parts[] = $text;

        return $parts;
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
    /** @param string|list<string> $html one message, or several to send in order */
    private function post(string|array $html, array $media = []): void
    {
        $chat = Setting::get('telegram_group_chat_id');
        if (! $chat || ! config('services.telegram.token')) {
            return;
        }

        dispatch(function () use ($chat, $html, $media) {
            try {
                $bot = app(TelegramBot::class);
                if ($media) {
                    $bot->sendMedia($chat, $media, (string) $html);
                } else {
                    foreach ((array) $html as $message) {
                        $bot->send($chat, $message);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Group announcement failed', ['message' => $e->getMessage()]);
            }
        })->afterResponse();
    }
}
