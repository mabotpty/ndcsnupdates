<?php

namespace App\Services;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\ReportEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram front-end for the admin backend. Only admin users who have linked
 * their Telegram account (admin > My account > Link Telegram) can do anything.
 */
class TelegramBot
{
    private const TYPES = ['warning' => '🔴 Warning', 'monitoring' => '🟡 Monitoring'];

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! $message || ! isset($message['text'])) {
            return;
        }

        // Only act in private chats, never in groups.
        if (($message['chat']['type'] ?? '') !== 'private') {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $text = trim($message['text']);

        if (preg_match('#^/link(?:@\w+)?\s+(\S+)#i', $text, $m)) {
            $this->link($chatId, $m[1]);

            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();
        if (! $user) {
            $this->send($chatId, "👋 This bot is for NDCSN updates admins.\n\nTo link your account, open the admin site → <b>My account</b> → <b>Link Telegram</b>, then send me:\n<code>/link YOURCODE</code>");

            return;
        }

        if (str_starts_with($text, '/')) {
            $this->command($user, $chatId, $text);

            return;
        }

        $this->conversation($user, $chatId, $text);
    }

    // ------------------------------------------------------------------ linking

    private function link(string $chatId, string $code): void
    {
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

        if ($cmd !== '/skip') {
            // Any new command abandons a half-finished conversation.
            Cache::forget($this->stateKey($chatId));
        }

        match ($cmd) {
            '/start', '/help' => $this->send($chatId, $this->help()),
            '/status' => $this->status($chatId),
            '/level' => $this->levelPicker($chatId),
            '/new' => $this->newPicker($chatId),
            '/warn' => $this->quickIncident($user, $chatId, 'warning', $args),
            '/monitor' => $this->quickIncident($user, $chatId, 'monitoring', $args),
            '/list' => $this->listIncidents($chatId),
            '/resolve' => $this->resolveById($chatId, $args),
            '/edit' => $this->editIncident($chatId, $args),
            '/news' => $this->quickNews($chatId, $args),
            '/sitrep' => $this->sitrep($chatId),
            '/sit' => $this->sit($chatId, $args),
            '/cancel' => $this->send($chatId, 'Cancelled.'),
            '/skip' => $this->conversation($user, $chatId, '/skip'),
            default => $this->send($chatId, "Unknown command. Send /help for the list."),
        };
    }

    private function help(): string
    {
        return <<<'TXT'
<b>NDCSN Updates bot</b>

/status – current level and open items
/level – change the safety alert level
/new – post a warning or monitoring item (guided)
/warn <i>Title | details</i> – quick warning
/monitor <i>Title | details</i> – quick monitoring item
/list – open items, with Resolve / Delete buttons
/resolve <i>ID</i> – mark an item resolved
/edit <i>ID Title | details</i> – change an item's text
/news <i>Title | source URL | text</i> – add a SAPS release
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
        $this->send($chatId, '<b>What are you posting?</b>', ['inline_keyboard' => [[
            ['text' => self::TYPES['warning'], 'callback_data' => 'new:warning'],
            ['text' => self::TYPES['monitoring'], 'callback_data' => 'new:monitoring'],
        ]]]);
    }

    private function conversation(User $user, string $chatId, string $text): void
    {
        $state = Cache::get($this->stateKey($chatId));
        if (! $state) {
            $this->send($chatId, 'Send /help to see what I can do.');

            return;
        }

        if ($state['step'] === 'title') {
            $state = ['step' => 'body', 'type' => $state['type'], 'title' => $text];
            Cache::put($this->stateKey($chatId), $state, now()->addMinutes(30));
            $this->send($chatId, "Title: <b>".e($text)."</b>\n\nNow send the details, or /skip to post with no details.");

            return;
        }

        $body = $text === '/skip' ? null : $text;
        Cache::forget($this->stateKey($chatId));
        $this->createIncident($user, $chatId, $state['type'], $state['title'], $body);
    }

    private function quickIncident(User $user, string $chatId, string $type, string $args): void
    {
        if ($args === '') {
            $this->send($chatId, "Usage: <code>/".($type === 'warning' ? 'warn' : 'monitor')." Title | details</code>");

            return;
        }
        [$title, $body] = $this->split($args, 2);
        $this->createIncident($user, $chatId, $type, $title, $body);
    }

    private function createIncident(User $user, string $chatId, string $type, string $title, ?string $body): void
    {
        $incident = Incident::create([
            'title' => $title,
            'body' => $body,
            'status' => $type,
            'published_at' => now(),
            'source' => 'telegram',
            'created_by' => $user->name,
        ]);

        $this->send($chatId, "✅ Posted as {$this->icon($type)} <b>#{$incident->id}</b> ".e($title)."\nIt's live on the site now.");
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
        return $this->icon($i->status)." <b>#{$i->id}</b> ".e($i->title)
            .($i->body ? "\n".e(mb_strimwidth($i->body, 0, 300, '…')) : '')
            ."\n<i>".$i->published_at->format('D j M, H:i').'</i>';
    }

    private function incidentButtons(Incident $i): array
    {
        $switch = $i->status === 'warning'
            ? ['text' => '🟡 To monitoring', 'callback_data' => "inc:{$i->id}:monitoring"]
            : ['text' => '🔴 To warning', 'callback_data' => "inc:{$i->id}:warning"];

        return ['inline_keyboard' => [[
            ['text' => '✅ Resolve', 'callback_data' => "inc:{$i->id}:resolved"],
            $switch,
            ['text' => '🗑 Delete', 'callback_data' => "inc:{$i->id}:delete"],
        ]]];
    }

    private function resolveById(string $chatId, string $args): void
    {
        $incident = ctype_digit($args) ? Incident::find((int) $args) : null;
        if (! $incident) {
            $this->send($chatId, 'Usage: <code>/resolve ID</code> (see /list for IDs)');

            return;
        }
        $incident->update(['status' => 'resolved']);
        $this->send($chatId, "✅ <b>#{$incident->id}</b> ".e($incident->title).' marked resolved.');
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

    private function quickNews(string $chatId, string $args): void
    {
        $parts = $this->split($args, 3);
        if ($args === '' || count(array_filter($parts)) < 1) {
            $this->send($chatId, "Usage: <code>/news Title | source URL | text</code>\nThe URL and text are optional.");

            return;
        }

        [$title, $second, $third] = $parts;
        $url = null;
        $body = $third;
        if ($second && filter_var($second, FILTER_VALIDATE_URL)) {
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

            case 'new':
                $type = $parts[1] ?? '';
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
        if (isset(Incident::STATUSES[$action])) {
            $incident->update(['status' => $action]);
            $incident->refresh();
            $edit(
                $action === 'resolved' ? "✅ <b>#{$id}</b> ".e($incident->title).' marked resolved.' : $this->incidentCard($incident),
                $action === 'resolved' ? null : $this->incidentButtons($incident),
            );

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
