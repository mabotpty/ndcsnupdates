<?php

namespace App\Services;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\ReportEntry;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeatherLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Telegram front-end for the admin backend. Only admin users who have linked
 * their Telegram account (admin > My account > Link Telegram) can do anything.
 */
class TelegramBot
{
    private const TYPES = ['warning' => '🔴 Warning', 'monitoring' => '🟡 Monitoring'];

    /** Telegram message id of the update being handled (used to order pasted multi-part text). */
    private ?int $messageId = null;

    public function handleUpdate(array $update): void
    {
        $this->messageId = isset($update['message']['message_id']) ? (int) $update['message']['message_id'] : null;

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! $message) {
            return;
        }

        // Photos/videos carry their text in "caption".
        $media = $this->extractMedia($message);
        if (! isset($message['text']) && ! $media) {
            return;
        }

        // Only act in private chats, never in groups.
        if (($message['chat']['type'] ?? '') !== 'private') {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $text = trim($message['text'] ?? $message['caption'] ?? '');

        if (preg_match('#^/link(?:@\w+)?\s+(\S+)#i', $text, $m)) {
            $this->link($chatId, $m[1]);

            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();
        if (! $user) {
            $this->send($chatId, "👋 This bot is for NDCSN updates admins.\n\nTo link your account, open the admin site → <b>My account</b> → <b>Link Telegram</b>, then send me:\n<code>/link YOURCODE</code>");

            return;
        }

        if ($media) {
            $this->mediaMessage($user, $chatId, $text, $media, $message['media_group_id'] ?? null);

            return;
        }

        if (str_starts_with($text, '/')) {
            $this->command($user, $chatId, $text);

            return;
        }

        $this->conversation($user, $chatId, $text);
    }

    // -------------------------------------------------------------------- media

    /** @return array{type: string, file_id: string}|null */
    private function extractMedia(array $message): ?array
    {
        if (! empty($message['photo'])) {
            // Telegram sends several sizes; the last is the largest.
            return ['type' => 'photo', 'file_id' => end($message['photo'])['file_id']];
        }
        if (! empty($message['video']['file_id'])) {
            return ['type' => 'video', 'file_id' => $message['video']['file_id']];
        }

        return null;
    }

    private function mediaMessage(User $user, string $chatId, string $caption, array $item, ?string $albumId): void
    {
        $state = Cache::get($this->stateKey($chatId));

        if ($state && in_array($state['step'], ['media', 'media_existing'], true)) {
            // Album items arrive as simultaneous requests, so serialise the read-modify-write.
            Cache::lock('tg:lock:'.$chatId, 5)->block(3, function () use ($chatId, $item) {
                $state = Cache::get($this->stateKey($chatId));
                if ($state && count($state['media']) < 10) {
                    $state['media'][] = $item;
                    Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
                }
            });

            if (! $albumId || Cache::add("tg:ack:{$chatId}:{$albumId}", 1, 60)) {
                $this->send($chatId, '📎 Attached. Send more (up to 10), or /done to post.');
            }

            return;
        }

        if (preg_match('#^/(warn|monitor)(?:@\w+)?\s+(.+)$#is', $caption, $m)) {
            [$title, $body, $whenText] = $this->split($m[2], 3);
            $when = $whenText ? $this->parseWhen($whenText) : null;
            if ($whenText && ! $when) {
                $this->send($chatId, "I couldn't read the time \"".e($whenText)."\". Nothing was posted.");

                return;
            }
            $this->createIncident($user, $chatId, strtolower($m[1]) === 'warn' ? 'warning' : 'monitoring', $title, $body, [$item], $when);

            return;
        }

        $this->send($chatId, "📎 To post media: use /new and send photos/videos when asked, add to an existing item with <code>/media ID</code>, or send <b>one</b> photo/video with the caption <code>/warn Title | details</code>.");
    }

    private function startMediaForExisting(string $chatId, string $args): void
    {
        $incident = ctype_digit($args) ? Incident::find((int) $args) : null;
        if (! $incident) {
            $this->send($chatId, 'Usage: <code>/media ID</code> (see /list for IDs), then send the photos/videos.');

            return;
        }

        Cache::put($this->stateKey($chatId), ['step' => 'media_existing', 'incident' => $incident->id, 'media' => []], now()->addMinutes(30));
        $this->send($chatId, "📷 Send photos/videos for <b>#{$incident->id}</b> ".e($incident->title).". Send /done when finished.");
    }

    // ------------------------------------------------------------------ linking

    private function link(string $chatId, string $code): void
    {
        // The bot's username is public, so stop anyone guessing link codes.
        $key = 'tg-link:'.$chatId;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->send($chatId, '⏳ Too many attempts. Try again in a few minutes.');

            return;
        }
        RateLimiter::hit($key, 900);

        $user = User::where('telegram_link_code', strtoupper($code))
            ->where('telegram_link_expires_at', '>', now())
            ->first();

        if (! $user) {
            $this->send($chatId, '❌ That code is invalid or has expired. Generate a new one on the admin site.');

            return;
        }

        User::where('telegram_chat_id', $chatId)->where('id', '!=', $user->id)->update(['telegram_chat_id' => null]);
        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_link_code' => null,
            'telegram_link_expires_at' => null,
        ])->save();

        $this->send($chatId, "✅ Linked as <b>".e($user->name)."</b>.\n\n".$this->help());
    }

    // ----------------------------------------------------------------- commands

    private function command(User $user, string $chatId, string $text): void
    {
        [$cmd, $args] = array_pad(preg_split('/\s+/', $text, 2), 2, '');
        $cmd = strtolower(preg_replace('/@\w+$/', '', $cmd));
        $args = trim($args);

        if (! in_array($cmd, ['/skip', '/done'], true)) {
            // Any new command abandons a half-finished conversation.
            Cache::forget($this->stateKey($chatId));
        }

        match ($cmd) {
            '/start', '/help' => $this->send($chatId, $this->help()),
            '/status' => $this->status($chatId),
            '/level' => $this->levelPicker($chatId),
            '/new' => $this->newPicker($chatId),
            '/weather' => $this->startWeather($chatId),
            '/warn' => $this->quickIncident($user, $chatId, 'warning', $args),
            '/monitor' => $this->quickIncident($user, $chatId, 'monitoring', $args),
            '/list' => $this->listIncidents($chatId),
            '/resolve' => $this->resolveById($chatId, $args),
            '/edit' => $this->editIncident($chatId, $args),
            '/news' => $this->quickNews($chatId, $args),
            '/sitrep' => $this->sitrep($chatId),
            '/sit' => $this->sit($chatId, $args),
            '/cancel' => $this->send($chatId, 'Cancelled.'),
            '/skip', '/done' => $this->conversation($user, $chatId, $cmd),
            '/media' => $this->startMediaForExisting($chatId, $args),
            default => $this->send($chatId, "Unknown command. Send /help for the list."),
        };
    }

    private function help(): string
    {
        return <<<'TXT'
<b>NDCSN Updates bot</b>

/status – current level and open items
/level – change the safety alert level
/new – post a warning, monitoring item or weather warning (guided)
/weather – post a weather warning (guided)
/warn <i>Title | details | time</i> – quick warning (attach <b>one</b> photo/video with this as its caption to include it)
/media <i>ID</i> – add photos/videos to an existing item (shared to the group only)
/monitor <i>Title | details | time</i> – quick monitoring item (time optional, e.g. 14:30)
/list – open items, with Resolve / Delete buttons
/resolve <i>ID</i> – mark an item resolved
/edit <i>ID Title | details</i> – change an item's text
/news – add a SAPS release step by step (or in one line: <i>/news Title | source link | text</i>)
/sitrep – situation report entries (tap to set status)
/sit <i>ID ok|warning|danger [text]</i> – update an entry
/cancel – abandon what you're doing

Newest items always appear first on the site.
TXT;
    }

    private function status(string $chatId): void
    {
        $level = AlertLevel::find((int) Setting::get('alert_level', '0'));
        $open = Incident::whereIn('status', ['warning', 'monitoring'])->newest()->get();

        $out = '<b>Level:</b> '.e($level?->name ?? '?')."\n".e($level?->description ?? '')."\n\n";
        $out .= $open->isEmpty()
            ? '✅ No open warnings or monitoring items.'
            : $open->map(fn (Incident $i) => $this->icon($i->status)." <b>#{$i->id}</b> ".e($i->title))->implode("\n");

        $this->send($chatId, $out);
    }

    // -------------------------------------------------------------------- level

    private function levelPicker(string $chatId): void
    {
        $current = (int) Setting::get('alert_level', '0');
        $rows = AlertLevel::orderBy('level')->get()->map(fn (AlertLevel $l) => [[
            'text' => ($l->level === $current ? '✅ ' : '').$l->name,
            'callback_data' => 'lvl:'.$l->level,
        ]])->all();

        $this->send($chatId, '<b>Set the safety alert level</b>', ['inline_keyboard' => $rows]);
    }

    // ---------------------------------------------------------------- incidents

    private function newPicker(string $chatId): void
    {
        $this->send($chatId, '<b>What are you posting?</b>', ['inline_keyboard' => [
            [
                ['text' => self::TYPES['warning'], 'callback_data' => 'new:warning'],
                ['text' => self::TYPES['monitoring'], 'callback_data' => 'new:monitoring'],
            ],
            [['text' => '⛈ Weather warning', 'callback_data' => 'new:weather']],
        ]]);
    }

    // ------------------------------------------------------------------ weather

    private function startWeather(string $chatId, ?int $editMessageId = null): void
    {
        Cache::put($this->stateKey($chatId), ['step' => 'wx_title'], now()->addMinutes(30));
        $text = "⛈ <b>New weather warning</b>\n\nSend a <b>short title</b> (e.g. <i>Heavy rain and flooding expected</i>). /cancel to stop.";

        $editMessageId
            ? $this->api('editMessageText', ['chat_id' => $chatId, 'message_id' => $editMessageId, 'parse_mode' => 'HTML', 'text' => $text])
            : $this->send($chatId, $text);
    }

    private function weatherPrompt(string $chatId, string $step, array $state = []): void
    {
        switch ($step) {
            case 'wx_type':
                $rows = array_chunk(array_map(
                    fn ($k, $n) => ['text' => $k, 'callback_data' => 'wxt:'.$n],
                    Incident::WEATHER_KINDS,
                    array_keys(Incident::WEATHER_KINDS),
                ), 2);
                $this->send($chatId, "<b>Type of weather?</b>\nTap one, or type your own.", ['inline_keyboard' => $rows]);
                break;

            case 'wx_level':
                $levels = WeatherLevel::where('level', '>', 0)->orderBy('level')->get();
                $list = $levels->map(fn ($l) => "<b>{$l->level}</b> – ".e($l->name).' ('.e($l->description).')')->implode("\n");
                $rows = array_chunk($levels->map(fn ($l) => ['text' => (string) $l->level, 'callback_data' => 'wxl:'.$l->level])->all(), 5);
                $this->send($chatId, "<b>Alert level (1–10)?</b>\n\n{$list}", ['inline_keyboard' => $rows]);
                break;

            case 'wx_area':
                $this->send($chatId, "📍 <b>Which area?</b> (e.g. <i>Durban North, Umhlanga</i>)\nOr /skip.");
                break;

            case 'wx_from':
                $this->send($chatId, "🕒 <b>Valid FROM?</b>\nSend a date/time like <code>14:30</code>, <code>tomorrow 06:00</code> or <code>17 Oct 06:00</code>.\nOr /skip for right now.");
                break;

            case 'wx_to':
                $this->send($chatId, "🕒 <b>Valid UNTIL?</b>\nSend a date/time like <code>22:00</code>, <code>tomorrow 06:00</code> or <code>17 Oct 18:00</code>.\nThe warning resolves itself quietly 1 hour after this.");
                break;

            case 'wx_narrative':
                $this->send($chatId, "📝 Now send the <b>detailed narrative</b>. Long text is fine — several messages are joined in order.\n\nSend /done when finished (or /done straight away for none).");
                break;
        }
    }

    private function weatherConversation(User $user, string $chatId, string $text, array $state): void
    {
        $put = fn (array $s) => Cache::put($this->stateKey($chatId), $s, now()->addMinutes(30));
        $go = function (string $step, array $changes = []) use ($chatId, $state, $put) {
            $state = array_merge($state, $changes, ['step' => $step]);
            $put($state);
            $this->weatherPrompt($chatId, $step, $state);
        };

        switch ($state['step']) {
            case 'wx_title':
                $go('wx_type', ['title' => mb_strimwidth($text, 0, 120)]);

                return;

            case 'wx_type':
                $go('wx_level', ['kind' => mb_strimwidth($text, 0, 60)]);

                return;

            case 'wx_level':
                if (! ctype_digit($text) || (int) $text < 1 || (int) $text > 10) {
                    $this->send($chatId, 'Send a number from 1 to 10, or tap one of the buttons above.');

                    return;
                }
                $go('wx_area', ['level' => (int) $text]);

                return;

            case 'wx_area':
                $go('wx_from', ['area' => $text === '/skip' ? null : mb_strimwidth($text, 0, 255)]);

                return;

            case 'wx_from':
                $from = $text === '/skip' ? now() : $this->parseFlexible($text);
                if (! $from) {
                    $this->send($chatId, "I couldn't read that. Try <code>14:30</code>, <code>tomorrow 06:00</code> or <code>17 Oct 06:00</code> — or /skip for now.");

                    return;
                }
                $go('wx_to', ['from' => $from->toIso8601String()]);

                return;

            case 'wx_to':
                $from = Carbon::parse($state['from']);
                $to = $this->parseFlexible($text, $from);
                if (! $to || $to->lte($from)) {
                    $this->send($chatId, "I couldn't read that, or it isn't after the start (".$from->format('D j M, H:i').'). Try <code>22:00</code> or <code>tomorrow 06:00</code>.');

                    return;
                }
                $go('wx_narrative', ['to' => $to->toIso8601String(), 'parts' => []]);

                return;

            case 'wx_narrative':
                if (! in_array($text, ['/done', '/skip'], true)) {
                    $this->collectText($chatId, 'wx_narrative', $text);

                    return;
                }

                Cache::forget($this->stateKey($chatId));
                $this->createWeather($user, $chatId, $state);

                return;
        }
    }

    private function createWeather(User $user, string $chatId, array $state): void
    {
        $narrative = $this->joinParts($state['parts'] ?? []);

        $w = Incident::create([
            'type' => 'weather',
            'status' => 'warning',
            'title' => $state['title'],
            'weather_kind' => $state['kind'],
            'weather_level' => $state['level'],
            'area' => $state['area'],
            'weather_from' => Carbon::parse($state['from']),
            'weather_to' => Carbon::parse($state['to']),
            'narrative' => $narrative !== '' ? $narrative : null,
            'published_at' => now(),
            'source' => 'telegram',
            'created_by' => $user->name,
        ]);

        $this->send($chatId, "✅ Weather warning <b>#{$w->id}</b> posted: ".e($w->title)
            ."\nLevel {$w->weather_level} · ".e($w->weather_kind)
            ."\n🕒 ".$w->weather_from->format('D j M, H:i').' → '.$w->weather_to->format('D j M, H:i')
            ."\nIt's live on the site and resolves itself 1 hour after it ends.");
    }

    /** Like parseWhen, but future times are fine, and a bare time earlier than $after rolls to the next day. */
    private function parseFlexible(string $text, ?Carbon $after = null): ?Carbon
    {
        $text = trim($text);
        $timeOnly = (bool) preg_match('/^\d{1,2}\s*[:h.]\s*\d{2}$/i', $text);
        if ($timeOnly) {
            $text = preg_replace('/\s*[h.]\s*/i', ':', $text);
        }

        try {
            $when = Carbon::parse($text, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        if ($timeOnly && $after && $when->lte($after)) {
            $when->addDay();
        }

        return $when;
    }

    /** Pasted text arrives as separate, possibly simultaneous, updates: keep them ordered by message id. */
    private function collectText(string $chatId, string $step, string $text): void
    {
        Cache::lock('tg:lock:'.$chatId, 5)->block(3, function () use ($chatId, $step, $text) {
            $state = Cache::get($this->stateKey($chatId));
            if ($state && $state['step'] === $step) {
                $state['parts'][$this->messageId ?? count($state['parts'])] = $text;
                Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
            }
        });

        if (Cache::add("tg:ack:text:{$chatId}", 1, 3)) {
            $this->send($chatId, '📝 Got it. Send more text, or /done to finish.');
        }
    }

    /** A part that hit Telegram's size limit was cut mid-text, so it is joined straight on. */
    private function joinParts(array $parts): string
    {
        ksort($parts);

        $body = '';
        $previous = null;
        foreach ($parts as $part) {
            $body .= ($previous === null ? '' : (mb_strlen($previous) >= 4000 ? '' : "\n\n")).$part;
            $previous = $part;
        }

        return $body;
    }

    private function conversation(User $user, string $chatId, string $text): void
    {
        $state = Cache::get($this->stateKey($chatId));
        if (! $state) {
            $this->send($chatId, 'Send /help to see what I can do.');

            return;
        }

        if (str_starts_with($state['step'], 'wx_')) {
            $this->weatherConversation($user, $chatId, $text, $state);

            return;
        }

        if (str_starts_with($state['step'], 'news_')) {
            $this->newsConversation($user, $chatId, $text, $state);

            return;
        }

        if ($state['step'] === 'title') {
            $state = ['step' => 'body', 'type' => $state['type'], 'title' => $text];
            Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
            $this->send($chatId, "Title: <b>".e($text)."</b>\n\nNow send the details, or /skip to post with no details.");

            return;
        }

        if ($state['step'] === 'body') {
            $state = ['step' => 'when', 'type' => $state['type'], 'title' => $state['title'], 'body' => $text === '/skip' ? null : $text];
            Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
            $this->send($chatId, "🕒 <b>When did it happen?</b>\nSend a time like <code>14:30</code>, <code>yesterday 22:15</code> or <code>9 Oct 14:30</code>.\n\nOr /skip to use the current time.");

            return;
        }

        if ($state['step'] === 'when') {
            $when = null;
            if ($text !== '/skip') {
                $when = $this->parseWhen($text);
                if (! $when) {
                    $this->send($chatId, "I couldn't read that time (or it's in the future). Try <code>14:30</code>, <code>yesterday 22:15</code> or <code>9 Oct 14:30</code> — or /skip for now.");

                    return;
                }
            }
            $state = ['step' => 'media', 'type' => $state['type'], 'title' => $state['title'], 'body' => $state['body'], 'when' => $when?->toIso8601String(), 'media' => []];
            Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
            $this->send($chatId, ($when ? '🕒 Occurred: <b>'.$when->format('D j M, H:i')."</b>\n\n" : '')."📷 Now send any <b>photos or videos</b> (up to 10). They're shared to the Telegram group only, not shown on the website.\n\nSend /done when finished, or /done straight away to post without any.");

            return;
        }

        if (! in_array($text, ['/done', '/skip'], true)) {
            $this->send($chatId, 'Send photos/videos, or /done to finish. /cancel to stop.');

            return;
        }

        Cache::forget($this->stateKey($chatId));

        if ($state['step'] === 'media_existing') {
            $incident = Incident::find($state['incident']);
            if (! $incident || ! $state['media']) {
                $this->send($chatId, 'Nothing attached.');

                return;
            }
            $incident->update(['media' => array_slice(array_merge($incident->media ?? [], $state['media']), 0, 30)]);
            app(Announcer::class)->mediaAdded($incident, $state['media']);
            $this->send($chatId, '📷 '.count($state['media']).' attached to <b>#'.$incident->id.'</b> and shared to the group.');

            return;
        }

        $this->createIncident($user, $chatId, $state['type'], $state['title'], $state['body'], $state['media'], $state['when'] ? Carbon::parse($state['when']) : null);
    }

    /** "14:30", "yesterday 22:15", "9 Oct 14:30"… in local time. Null if unreadable or in the future. */
    private function parseWhen(string $text): ?Carbon
    {
        $text = trim($text);
        $timeOnly = (bool) preg_match('/^\d{1,2}\s*[:h.]\s*\d{2}$/i', $text);
        if ($timeOnly) {
            $text = preg_replace('/\s*[h.]\s*/i', ':', $text);
        }

        try {
            $when = Carbon::parse($text, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        // Reported just after midnight about "22:15" means last night.
        if ($timeOnly && $when->gt(now()->addMinutes(5))) {
            $when->subDay();
        }

        return $when->gt(now()->addMinutes(5)) ? null : $when;
    }

    private function quickIncident(User $user, string $chatId, string $type, string $args): void
    {
        if ($args === '') {
            $this->send($chatId, "Usage: <code>/".($type === 'warning' ? 'warn' : 'monitor')." Title | details | time</code>\nDetails and time are optional; time defaults to now (e.g. <code>14:30</code>).");

            return;
        }
        [$title, $body, $whenText] = $this->split($args, 3);
        $when = $whenText ? $this->parseWhen($whenText) : null;
        if ($whenText && ! $when) {
            $this->send($chatId, "I couldn't read the time \"".e($whenText)."\" (or it's in the future). Nothing was posted. Try <code>14:30</code> or <code>yesterday 22:15</code>.");

            return;
        }
        $this->createIncident($user, $chatId, $type, $title, $body, [], $when);
    }

    private function createIncident(User $user, string $chatId, string $type, string $title, ?string $body, array $media = [], ?Carbon $when = null): void
    {
        $incident = Incident::create([
            'title' => $title,
            'body' => $body,
            'media' => $media ?: null,
            'status' => $type,
            'published_at' => $when ?? now(),
            'source' => 'telegram',
            'created_by' => $user->name,
        ]);

        $this->send($chatId, "✅ Posted as {$this->icon($type)} <b>#{$incident->id}</b> ".e($title)."\n🕒 ".$incident->published_at->format('D j M, H:i')."\nIt's live on the site now."
            .($media ? "\n📷 ".count($media).' photo/video(s) shared to the Telegram group.' : ''));
    }

    private function listIncidents(string $chatId): void
    {
        $open = Incident::whereIn('status', ['warning', 'monitoring'])->newest()->get();
        if ($open->isEmpty()) {
            $this->send($chatId, '✅ Nothing open right now.');

            return;
        }

        foreach ($open as $incident) {
            $this->send($chatId, $this->incidentCard($incident), $this->incidentButtons($incident));
        }
    }

    private function incidentCard(Incident $i): string
    {
        return ($i->isWeather() ? '⛈' : $this->icon($i->status))." <b>#{$i->id}</b> ".e($i->title)
            .($i->isWeather() ? "\nLevel {$i->weather_level} · ".e($i->weather_kind).' · until '.$i->weather_to?->format('D j M, H:i') : '')
            .($i->body ? "\n".e(mb_strimwidth($i->body, 0, 300, '…')) : '')
            ."\n<i>".$i->published_at->format('D j M, H:i').'</i>';
    }

    private function incidentButtons(Incident $i): array
    {
        $switch = $i->status === 'warning'
            ? ['text' => '🟡 To monitoring', 'callback_data' => "inc:{$i->id}:monitoring"]
            : ['text' => '🔴 To warning', 'callback_data' => "inc:{$i->id}:warning"];

        return ['inline_keyboard' => [array_values(array_filter([
            ['text' => '✅ Resolve', 'callback_data' => "inc:{$i->id}:resolved"],
            // Weather warnings are either active or resolved; there is no "monitoring" for them.
            $i->isWeather() ? null : $switch,
            ['text' => '🗑 Delete', 'callback_data' => "inc:{$i->id}:delete"],
        ]))]];
    }

    private function resolveById(string $chatId, string $args): void
    {
        $incident = ctype_digit($args) ? Incident::find((int) $args) : null;
        if (! $incident) {
            $this->send($chatId, 'Usage: <code>/resolve ID</code> (see /list for IDs)');

            return;
        }
        if ($incident->status === 'resolved') {
            $this->send($chatId, "<b>#{$incident->id}</b> is already resolved.");

            return;
        }

        if (! Setting::get('telegram_group_chat_id')) {
            $incident->update(['status' => 'resolved']);
            $this->send($chatId, "✅ <b>#{$incident->id}</b> ".e($incident->title).' marked resolved.');

            return;
        }

        $this->send($chatId, $this->resolvePrompt($incident), $this->resolveButtons($incident));
    }

    private function resolvePrompt(Incident $i): string
    {
        return "✅ Resolve <b>#{$i->id}</b> ".e($i->title)."?\n\nShould the Telegram group be sent a resolved alert?";
    }

    private function resolveButtons(Incident $i): array
    {
        return ['inline_keyboard' => [
            [['text' => '📣 Resolve & alert group', 'callback_data' => "inc:{$i->id}:resolve_alert"]],
            [['text' => '🔕 Resolve quietly', 'callback_data' => "inc:{$i->id}:resolve_quiet"]],
            [['text' => 'Cancel', 'callback_data' => "inc:{$i->id}:keep"]],
        ]];
    }

    private function editIncident(string $chatId, string $args): void
    {
        if (! preg_match('/^(\d+)\s+(.+)$/s', $args, $m) || ! ($incident = Incident::find((int) $m[1]))) {
            $this->send($chatId, 'Usage: <code>/edit ID New title | new details</code>');

            return;
        }
        [$title, $body] = $this->split($m[2], 2);
        $incident->update(['title' => $title, 'body' => $body ?? $incident->body]);
        $this->send($chatId, "✏️ Updated <b>#{$incident->id}</b>.");
    }

    // --------------------------------------------------------------------- news

    /** Step-by-step SAPS release: title → source link → release time → full text → /done. */
    private function newsConversation(User $user, string $chatId, string $text, array $state): void
    {
        $put = fn (array $s) => Cache::put($this->stateKey($chatId), $s, now()->addMinutes(30));

        switch ($state['step']) {
            case 'news_title':
                $put(['step' => 'news_url', 'title' => $text]);
                $this->send($chatId, 'Title: <b>'.e($text)."</b>\n\n🔗 Send the <b>source link</b> (e.g. the Facebook post), or /skip.");

                return;

            case 'news_url':
                $url = null;
                if ($text !== '/skip') {
                    if (! preg_match('#^https?://\S+$#i', $text) || ! filter_var($text, FILTER_VALIDATE_URL)) {
                        $this->send($chatId, "That doesn't look like a web link (it must start with http:// or https://). Send it again, or /skip.");

                        return;
                    }
                    $url = $text;
                }
                $put(['step' => 'news_when', 'title' => $state['title'], 'url' => $url]);
                $this->send($chatId, "🕒 <b>When was it released?</b>\nSend a time like <code>14:30</code>, <code>yesterday 22:15</code> or <code>9 Oct 14:30</code>.\n\nOr /skip to use the current time.");

                return;

            case 'news_when':
                $when = null;
                if ($text !== '/skip') {
                    $when = $this->parseWhen($text);
                    if (! $when) {
                        $this->send($chatId, "I couldn't read that time (or it's in the future). Try <code>14:30</code>, <code>yesterday 22:15</code> or <code>9 Oct 14:30</code> — or /skip for now.");

                        return;
                    }
                }
                $put(['step' => 'news_body', 'title' => $state['title'], 'url' => $state['url'], 'when' => $when?->toIso8601String(), 'parts' => []]);
                $this->send($chatId, ($when ? '🕒 Released: <b>'.$when->format('D j M, H:i')."</b>\n\n" : '')."📝 Now send the <b>full text</b> of the release. Pasting a long text is fine — if Telegram splits it into several messages they're joined in order.\n\nSend /done when you've sent it all.");

                return;

            case 'news_body':
                if (! in_array($text, ['/done', '/skip'], true)) {
                    $this->collectText($chatId, 'news_body', $text);

                    return;
                }

                Cache::forget($this->stateKey($chatId));
                $this->publishNews($chatId, $state);

                return;
        }
    }

    private function publishNews(string $chatId, array $state): void
    {
        $body = $this->joinParts($state['parts']);

        $url = $state['url'];
        $item = NewsItem::create([
            'title' => $state['title'],
            'source_url' => $url,
            'source_name' => $url ? (preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?: 'Source') : null,
            'body' => $body !== '' ? $body : null,
            'published_at' => $state['when'] ? Carbon::parse($state['when']) : now(),
        ]);

        $this->send($chatId, '📰 Release <b>#'.$item->id.'</b> published: '.e($item->title)."\n🕒 ".$item->published_at->format('D j M, H:i')
            ."\nIt's live on the site".(Setting::get('telegram_group_chat_id') ? ' and shared to the Telegram group.' : '.'));
    }

    private function quickNews(string $chatId, string $args): void
    {
        if ($args === '') {
            Cache::put($this->stateKey($chatId), ['step' => 'news_title'], now()->addMinutes(30));
            $this->send($chatId, "📰 <b>New SAPS release</b>\n\nSend the <b>title</b>. /cancel to stop.\n\n<i>Tip: the one-line form also works: /news Title | source link | text</i>");

            return;
        }

        $parts = $this->split($args, 3);
        if (count(array_filter($parts)) < 1) {
            $this->send($chatId, "Usage: <code>/news Title | source URL | text</code>, or just <code>/news</code> for step-by-step.");

            return;
        }

        [$title, $second, $third] = $parts;
        $url = null;
        $body = $third;
        if ($second && preg_match('#^https?://#i', $second) && filter_var($second, FILTER_VALIDATE_URL)) {
            $url = $second;
        } elseif ($second) {
            // Second part wasn't a URL, treat everything after the title as the text.
            $body = trim($second.($third ? ' | '.$third : ''));
        }

        $item = NewsItem::create([
            'title' => $title,
            'source_url' => $url,
            'source_name' => $url ? (parse_url($url, PHP_URL_HOST) ? preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST)) : 'Source') : null,
            'body' => $body,
            'published_at' => now(),
        ]);

        $this->send($chatId, "📰 News item <b>#{$item->id}</b> added: ".e($title));
    }

    // ------------------------------------------------------------------- sitrep

    private function sitrep(string $chatId): void
    {
        foreach (ReportEntry::with('group')->orderBy('report_group_id')->orderBy('sort')->get() as $entry) {
            $this->send($chatId, $this->entryCard($entry), $this->entryButtons($entry));
        }
    }

    private function entryCard(ReportEntry $e): string
    {
        $label = $e->group->title.($e->area ? ' – '.$e->area : '');

        return $this->entryIcon($e->status)." <b>#{$e->id}</b> ".e($label)."\n".e(implode(' • ', $e->bullets()));
    }

    private function entryButtons(ReportEntry $e): array
    {
        return ['inline_keyboard' => [[
            ['text' => '🟢 All clear', 'callback_data' => "sit:{$e->id}:ok"],
            ['text' => '🟠 Caution', 'callback_data' => "sit:{$e->id}:warning"],
            ['text' => '🔴 Avoid', 'callback_data' => "sit:{$e->id}:danger"],
        ]]];
    }

    private function sit(string $chatId, string $args): void
    {
        if (! preg_match('/^(\d+)\s+(ok|warning|danger)(?:\s+(.+))?$/is', $args, $m) || ! ($entry = ReportEntry::find((int) $m[1]))) {
            $this->send($chatId, "Usage: <code>/sit ID ok|warning|danger [text]</code>\nUse /sitrep to see entry IDs. Separate several bullet points with |");

            return;
        }

        $entry->status = strtolower($m[2]);
        if (! empty($m[3])) {
            $entry->lines = implode("\n", $this->split($m[3], 20));
        }
        $entry->save();

        $this->send($chatId, '✅ Updated.'."\n".$this->entryCard($entry->fresh('group')));
    }

    // ---------------------------------------------------------------- callbacks

    private function handleCallback(array $cb): void
    {
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        $messageId = $cb['message']['message_id'] ?? null;
        $user = User::where('telegram_chat_id', $chatId)->first();

        if (! $user || ! $messageId) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Not linked.']);

            return;
        }

        $parts = explode(':', $cb['data'] ?? '');
        $answer = 'Done';

        switch ($parts[0]) {
            case 'lvl':
                $level = AlertLevel::find((int) ($parts[1] ?? -1));
                if ($level) {
                    Setting::put('alert_level', (string) $level->level);
                    $answer = 'Level set';
                    $this->api('editMessageText', [
                        'chat_id' => $chatId, 'message_id' => $messageId, 'parse_mode' => 'HTML',
                        'text' => '✅ Level set to <b>'.e($level->name)."</b>\n".e($level->description),
                    ]);
                }
                break;

            case 'wxt':
            case 'wxl':
                $state = Cache::get($this->stateKey($chatId));
                $isType = $parts[0] === 'wxt';
                if ($state && $state['step'] === ($isType ? 'wx_type' : 'wx_level')) {
                    $value = $isType ? (Incident::WEATHER_KINDS[(int) ($parts[1] ?? -1)] ?? null) : ((int) ($parts[1] ?? 0) ?: null);
                    if ($value !== null) {
                        $this->api('editMessageText', [
                            'chat_id' => $chatId, 'message_id' => $messageId, 'parse_mode' => 'HTML',
                            'text' => ($isType ? 'Type: ' : 'Level: ').'<b>'.e((string) $value).'</b>',
                        ]);

                        if ($isType && $value === 'Other') {
                            $this->send($chatId, 'Type the type of weather (e.g. <i>Waterspout</i>).');
                        } else {
                            $next = $isType ? 'wx_level' : 'wx_area';
                            $state = array_merge($state, ['step' => $next], $isType ? ['kind' => $value] : ['level' => $value]);
                            Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
                            $this->weatherPrompt($chatId, $next, $state);
                        }
                    }
                }
                break;

            case 'new':
                $type = $parts[1] ?? '';
                if ($type === 'weather') {
                    $this->startWeather($chatId, $messageId);
                    break;
                }
                if (isset(self::TYPES[$type])) {
                    Cache::put($this->stateKey($chatId), ['step' => 'title', 'type' => $type], now()->addMinutes(30));
                    $this->api('editMessageText', [
                        'chat_id' => $chatId, 'message_id' => $messageId, 'parse_mode' => 'HTML',
                        'text' => self::TYPES[$type]."\n\nSend the <b>title</b> (short heading, e.g. <i>Protest on Umhlanga Rocks Dr</i>). /cancel to stop.",
                    ]);
                }
                break;

            case 'inc':
                $answer = $this->incidentCallback((int) ($parts[1] ?? 0), $parts[2] ?? '', $chatId, $messageId);
                break;

            case 'sit':
                $entry = ReportEntry::with('group')->find((int) ($parts[1] ?? 0));
                if ($entry && in_array($parts[2] ?? '', ['ok', 'warning', 'danger'], true)) {
                    $entry->update(['status' => $parts[2]]);
                    $this->api('editMessageText', [
                        'chat_id' => $chatId, 'message_id' => $messageId, 'parse_mode' => 'HTML',
                        'text' => $this->entryCard($entry), 'reply_markup' => $this->entryButtons($entry),
                    ]);
                    $answer = 'Updated';
                }
                break;
        }

        $this->api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => $answer]);
    }

    private function incidentCallback(int $id, string $action, string $chatId, int $messageId): string
    {
        $incident = Incident::find($id);
        if (! $incident) {
            $this->api('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => 'That item no longer exists.']);

            return 'Not found';
        }

        $edit = fn (string $text, ?array $markup = null) => $this->api('editMessageText', array_filter([
            'chat_id' => $chatId, 'message_id' => $messageId, 'parse_mode' => 'HTML', 'text' => $text, 'reply_markup' => $markup,
        ]));

        if ($action === 'delete') {
            $edit("🗑 Delete <b>#{$incident->id}</b> ".e($incident->title).'? This cannot be undone.', ['inline_keyboard' => [[
                ['text' => 'Yes, delete', 'callback_data' => "inc:{$id}:delete!"],
                ['text' => 'Keep it', 'callback_data' => "inc:{$id}:keep"],
            ]]]);

            return 'Confirm';
        }
        if ($action === 'delete!') {
            $incident->delete();
            $edit("🗑 Deleted <b>#{$id}</b>.");

            return 'Deleted';
        }
        if ($action === 'keep') {
            $edit($this->incidentCard($incident), $this->incidentButtons($incident));

            return 'Kept';
        }
        if ($action === 'resolved' && Setting::get('telegram_group_chat_id')) {
            // Ask first: not every resolved incident needs to go to the group.
            $edit($this->resolvePrompt($incident), $this->resolveButtons($incident));

            return 'Alert the group?';
        }
        if (in_array($action, ['resolved', 'resolve_alert', 'resolve_quiet'], true)) {
            if ($incident->status !== 'resolved') {
                $incident->announce = $action === 'resolve_alert';
                $incident->update(['status' => 'resolved']);
            }
            $edit("✅ <b>#{$id}</b> ".e($incident->title).' marked resolved.'
                .($action === 'resolve_alert' ? "\n📣 Alert sent to the group." : (Setting::get('telegram_group_chat_id') ? "\n🔕 No alert sent to the group." : '')));

            return 'Resolved';
        }
        if (isset(Incident::STATUSES[$action])) {
            $incident->update(['status' => $action]);
            $incident->refresh();
            $edit($this->incidentCard($incident), $this->incidentButtons($incident));

            return 'Updated';
        }

        return 'Unknown action';
    }

    // ------------------------------------------------------------------ helpers

    /** Split on "|" into at most $max trimmed parts (missing parts are null). */
    private function split(string $text, int $max): array
    {
        $parts = array_map('trim', explode('|', $text, $max));

        return array_map(fn ($p) => $p === '' ? null : $p, array_pad($parts, $max, null));
    }

    private function icon(string $status): string
    {
        return ['warning' => '🔴', 'monitoring' => '🟡', 'resolved' => '✅'][$status] ?? '•';
    }

    private function entryIcon(string $status): string
    {
        return ['ok' => '🟢', 'warning' => '🟠', 'danger' => '🔴'][$status] ?? '•';
    }

    private function stateKey(string $chatId): string
    {
        return 'tg:state:'.$chatId;
    }

    /** Photos/videos with the text as caption (sent separately if the caption would be too long). */
    public function sendMedia(string $chatId, array $media, string $caption): void
    {
        $captionFits = mb_strlen($caption) <= 1024;

        foreach (array_chunk($media, 10) as $n => $chunk) {
            $cap = ($n === 0 && $captionFits) ? $caption : null;

            if (count($chunk) === 1) {
                $isVideo = $chunk[0]['type'] === 'video';
                $this->api($isVideo ? 'sendVideo' : 'sendPhoto', array_filter([
                    'chat_id' => $chatId,
                    $isVideo ? 'video' : 'photo' => $chunk[0]['file_id'],
                    'caption' => $cap,
                    'parse_mode' => $cap ? 'HTML' : null,
                ]));

                continue;
            }

            $items = [];
            foreach ($chunk as $i => $m) {
                $items[] = array_filter([
                    'type' => $m['type'] === 'video' ? 'video' : 'photo',
                    'media' => $m['file_id'],
                    'caption' => $i === 0 ? $cap : null,
                    'parse_mode' => ($i === 0 && $cap) ? 'HTML' : null,
                ]);
            }
            $this->api('sendMediaGroup', ['chat_id' => $chatId, 'media' => $items]);
        }

        if (! $captionFits) {
            $this->send($chatId, $caption);
        }
    }

    public function send(string $chatId, string $text, ?array $markup = null): void
    {
        $this->api('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $markup,
        ], fn ($v) => $v !== null));
    }

    public function api(string $method, array $params = []): array
    {
        $token = config('services.telegram.token');
        if (! $token) {
            return [];
        }

        $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        if (! $response->successful()) {
            Log::warning('Telegram API error', ['method' => $method, 'body' => $response->body()]);
        }

        return $response->json() ?? [];
    }
}
