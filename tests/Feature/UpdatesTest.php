<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.telegram.token' => 'TEST', 'services.telegram.secret' => 'sekret']);
    }

    public function test_public_pages_render_with_seeded_content(): void
    {
        $this->get('/')->assertOk()->assertSee('Yellow - Level 1')->assertSee('March and March Update');
        $this->get('/news')->assertOk()->assertSee('HAPPENING NOW IN DURBAN');
    }

    public function test_newest_incidents_are_listed_first(): void
    {
        Incident::create(['title' => 'Older item', 'status' => 'monitoring', 'published_at' => now()->subHours(3)]);
        Incident::create(['title' => 'Newest item', 'status' => 'monitoring', 'published_at' => now()]);
        Incident::create(['title' => 'Middle item', 'status' => 'monitoring', 'published_at' => now()->subHour()]);

        $this->get('/')->assertSeeInOrder(['Newest item', 'Middle item', 'Older item']);
    }

    public function test_resolved_items_move_columns_and_expire(): void
    {
        $i = Incident::create(['title' => 'Road closure', 'status' => 'warning']);
        $i->update(['status' => 'resolved']);
        $this->assertNotNull($i->fresh()->resolved_at);
        $this->get('/')->assertSee('Road closure');

        $i->update(['resolved_at' => now()->subDays(5)]);
        $this->get('/')->assertDontSee('Road closure');
    }

    public function test_admin_requires_login_and_can_post(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/incidents', ['title' => 'From the web', 'status' => 'warning', 'body' => 'x'])
            ->assertRedirect('/admin/incidents');
        $this->assertDatabaseHas('incidents', ['title' => 'From the web', 'created_by' => $user->name]);

        $this->actingAs($user)->post('/admin/level', ['level' => 3])->assertRedirect();
        $this->assertSame('3', Setting::get('alert_level'));
        $this->get('/')->assertSee('Orange - Level 3');

        foreach (['/admin', '/admin/incidents', '/admin/incidents/create', '/admin/report', '/admin/news', '/admin/news/create', '/admin/account', '/admin/users'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_only_http_links_and_security_headers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/news', ['title' => 'x', 'source_url' => 'javascript://%0Aalert(1)'])
            ->assertSessionHasErrors('source_url');
        $this->actingAs($user)->post('/admin/news', ['title' => 'ok', 'source_url' => 'https://example.com/a'])
            ->assertSessionDoesntHaveErrors();

        $this->get('/')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_link_code_guessing_is_rate_limited(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $user->forceFill(['telegram_link_code' => 'GOODCODE', 'telegram_link_expires_at' => now()->addMinutes(5)])->save();

        foreach (range(1, 5) as $n) {
            $this->say("/link BAD0000{$n}", 777);
        }
        $this->say('/link GOODCODE', 777); // correct, but the chat is now locked out
        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_telegram_group_banner(): void
    {
        $this->get('/')->assertSee('https://t.me/ndcsncommunityupdates');

        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/settings', ['resolved_hours' => 48, 'telegram_group_url' => ''])->assertRedirect();
        $this->get('/')->assertDontSee('Join on Telegram');
    }

    private function groupPosts(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage') && ($pair[0]['chat_id'] ?? null) === '-100123')
            ->map(fn ($pair) => $pair[0]["text"])->unique()->values()->all();
    }

    public function test_changes_from_site_and_bot_are_announced_in_the_group(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $admin = User::factory()->create(['telegram_chat_id' => '555']);

        // From the website.
        $this->actingAs($admin)->post('/admin/incidents', ['title' => 'Web warning', 'status' => 'warning', 'body' => 'Roads blocked']);
        $this->actingAs($admin)->post('/admin/level', ['level' => 3]);
        $this->actingAs($admin)->post('/admin/news', ['title' => 'Web release', 'source_url' => 'https://example.com/r']);
        $web = Incident::where('title', 'Web warning')->first();
        $this->actingAs($admin)->post("/admin/incidents/{$web->id}/status", ['status' => 'resolved', 'announce' => 1]);

        // From the bot.
        $this->say('/monitor Bot item | Seen by patrol');
        $this->say('/news Bot release');

        $posts = implode("\n---\n", $this->groupPosts());
        foreach (['WARNING', 'Web warning', 'Roads blocked', 'Orange - Level 3', 'Web release', 'https://example.com/r', 'RESOLVED', 'MONITORING', 'Bot item', 'Seen by patrol', 'Bot release'] as $needle) {
            $this->assertStringContainsString($needle, $posts);
        }

        // Re-saving the same level or editing text must not spam the group.
        $before = count($this->groupPosts());
        $this->actingAs($admin)->post('/admin/level', ['level' => 3]);
        $this->actingAs($admin)->put("/admin/incidents/{$web->id}", ['title' => 'Web warning edited', 'status' => 'resolved']);
        $this->assertSame($before, count($this->groupPosts()));
    }

    private function photo(string $id, ?string $caption = null, ?string $album = null): void
    {
        $this->tg(['message' => array_filter([
            'photo' => [['file_id' => $id.'_small'], ['file_id' => $id]],
            'caption' => $caption,
            'media_group_id' => $album,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555],
        ])]);
    }

    private function sentTo(string $method): array
    {
        return Http::recorded()->filter(fn ($p) => str_contains($p[0]->url(), $method))->map(fn ($p) => $p[0]->data())->unique(fn ($d) => json_encode($d))->values()->all();
    }

    public function test_media_is_shared_to_group_and_flagged_on_site(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        User::factory()->create(['telegram_chat_id' => '555']);

        // Guided flow with an album + a video.
        $this->say('/new');
        $this->press('new:warning');
        $this->say('Crowd at the circle');
        $this->say('Police on scene');
        $this->say('/skip');
        $this->photo('PH1', null, 'album1');
        $this->photo('PH2', null, 'album1');
        $this->tg(['message' => ['video' => ['file_id' => 'VID1'], 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]]]);
        $this->say('/done');

        $incident = Incident::where('title', 'Crowd at the circle')->firstOrFail();
        $this->assertSame([
            ['type' => 'photo', 'file_id' => 'PH1'], ['type' => 'photo', 'file_id' => 'PH2'], ['type' => 'video', 'file_id' => 'VID1'],
        ], $incident->media);

        $album = $this->sentTo('sendMediaGroup');
        $this->assertCount(3, $album[0]['media']);
        $this->assertSame('-100123', $album[0]['chat_id']);
        $this->assertStringContainsString('Crowd at the circle', $album[0]['media'][0]['caption']);
        $this->assertArrayNotHasKey('caption', $album[0]['media'][1]);

        // Website shows only a note, never the files.
        $this->get('/')->assertSee('Imagery available in the Telegram group')->assertDontSee('PH1');

        // Single photo with a /warn caption.
        $this->photo('PH3', '/warn Smoke seen | Near the bridge');
        $quick = Incident::where('title', 'Smoke seen')->firstOrFail();
        $this->assertSame([['type' => 'photo', 'file_id' => 'PH3']], $quick->media);
        $this->assertSame('PH3', $this->sentTo('sendPhoto')[0]['photo']);

        // Add more to an existing item.
        $this->say("/media {$quick->id}");
        $this->photo('PH4');
        $this->say('/done');
        $this->assertCount(2, $quick->fresh()->media);
        $this->assertCount(2, $this->sentTo('sendPhoto'));
    }

    public function test_bot_asks_for_time_of_occurrence(): void
    {
        Http::fake();
        $this->travelTo(now()->setTime(15, 0));
        User::factory()->create(['telegram_chat_id' => '555']);

        // Guided: a time is read, bad input re-asks, /skip means now.
        $this->say('/new');
        $this->press('new:warning');
        $this->say('Gunshots heard');
        $this->say('/skip');
        $this->say('banana');
        $this->say('13:30');
        $this->say('/done');
        $i = Incident::where('title', 'Gunshots heard')->firstOrFail();
        $this->assertSame(now()->setTime(13, 30)->toDateTimeString(), $i->published_at->toDateTimeString());

        $this->say('/new');
        $this->press('new:monitoring');
        $this->say('Smoke visible');
        $this->say('/skip');
        $this->say('/skip');
        $this->say('/done');
        $this->assertTrue(Incident::where('title', 'Smoke visible')->first()->published_at->diffInSeconds(now(), true) < 5);

        // Quick form, third part is the time; a later-today time means yesterday.
        $this->say('/warn Protest | Umhlanga Rocks Dr | yesterday 22:15');
        $this->assertSame(now()->subDay()->setTime(22, 15)->toDateTimeString(), Incident::where('title', 'Protest')->first()->published_at->toDateTimeString());
        $this->say('/warn Late report | | 23:00');
        $this->assertSame(now()->subDay()->setTime(23, 0)->toDateTimeString(), Incident::where('title', 'Late report')->first()->published_at->toDateTimeString());

        // Unreadable time in quick form posts nothing.
        $this->say('/warn Nope | x | whenever');
        $this->assertDatabaseMissing('incidents', ['title' => 'Nope']);

        // Newest occurrence is listed first on the site (13:30 today above yesterday's).
        $this->get('/')->assertSeeInOrder(['Gunshots heard', 'Late report']);
    }

    public function test_media_without_context_is_not_posted(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        User::factory()->create(['telegram_chat_id' => '555']);

        $this->photo('LOOSE');
        $this->assertSame([], $this->sentTo('sendPhoto'));
        $this->assertSame(0, Incident::where('title', '!=', 'March and March Update')->count());
    }

    public function test_resolved_alerts_are_opt_in(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $admin = User::factory()->create(['telegram_chat_id' => '555']);
        $mk = fn ($t) => Incident::create(['title' => $t, 'status' => 'warning']);
        $resolved = fn () => collect($this->groupPosts())->filter(fn ($t) => str_contains($t, 'RESOLVED'))->implode('|');

        // Website: quiet by default, alert when asked.
        $a = $mk('Quiet one');
        $b = $mk('Loud one');
        $this->actingAs($admin)->post("/admin/incidents/{$a->id}/status", ['status' => 'resolved']);
        $this->actingAs($admin)->post("/admin/incidents/{$b->id}/status", ['status' => 'resolved', 'announce' => 1]);
        $this->assertSame('resolved', $a->fresh()->status);
        $this->assertStringNotContainsString('Quiet one', $resolved());
        $this->assertStringContainsString('Loud one', $resolved());

        // Bot: the button asks first; nothing is resolved or posted until a choice is made.
        $c = $mk('Bot quiet');
        $d = $mk('Bot loud');
        $this->press("inc:{$c->id}:resolved");
        $this->assertSame('warning', $c->fresh()->status);
        $this->assertStringContainsString('Should the Telegram group', json_encode($this->sentTo('editMessageText')));

        $this->press("inc:{$c->id}:resolve_quiet");
        $this->press("inc:{$d->id}:resolve_alert");
        $this->assertSame('resolved', $c->fresh()->status);
        $this->assertSame('resolved', $d->fresh()->status);
        $this->assertStringNotContainsString('Bot quiet', $resolved());
        $this->assertStringContainsString('Bot loud', $resolved());

        // /resolve ID prompts too.
        $e = $mk('Slash resolve');
        $this->say("/resolve {$e->id}");
        $this->assertSame('warning', $e->fresh()->status);

        // With no group configured there's nothing to ask.
        Setting::put('telegram_group_chat_id', null);
        $this->say("/resolve {$e->id}");
        $this->assertSame('resolved', $e->fresh()->status);
    }

    public function test_releases_are_posted_in_full_in_the_agreed_format(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/admin/news', [
            'title' => 'Short release', 'source_url' => 'https://example.com/short', 'body' => "Line one & two.\n\nParagraph <b>two</b>.",
        ]);
        $short = $this->groupPosts();
        $this->assertCount(1, $short);
        $this->assertSame(
            "📰 <b>SAPS RELEASE</b>\n<b>Short release</b>\n\nLine one &amp; two.\n\nParagraph &lt;b&gt;two&lt;/b&gt;.\n\nSource: https://example.com/short\n\nNDCSN Updates: ".url('/news'),
            $short[0],
        );

        // A long release is split into ordered messages (each under Telegram's limit), nothing lost.
        $long = collect(range(1, 180))->map(fn ($n) => "Update {$n}: police are monitoring the march & maintaining order.")->implode(' '); // ~10k chars
        $this->actingAs($admin)->post('/admin/news', ['title' => 'Long release', 'source_url' => 'https://example.com/long', 'body' => $long]);

        $parts = collect($this->groupPosts())->filter(fn ($t) => ! str_contains($t, 'Short release'))->values();
        $this->assertGreaterThan(2, $parts->count());
        $parts->each(fn ($t) => $this->assertLessThanOrEqual(4096, mb_strlen($t)));
        $this->assertStringStartsWith("📰 <b>SAPS RELEASE</b>\n<b>Long release</b>", $parts->first());
        $this->assertStringEndsWith("Source: https://example.com/long\n\nNDCSN Updates: ".url('/news'), $parts->last());
        $this->assertSame(0, $parts->slice(0, -1)->filter(fn ($t) => str_contains($t, 'NDCSN Updates:'))->count());

        $rebuilt = $parts->map(fn ($t) => html_entity_decode($t))->implode(' ');
        foreach (range(1, 180) as $n) {
            $this->assertStringContainsString("Update {$n}: police are monitoring the march & maintaining order.", $rebuilt);
        }
    }

    public function test_no_group_configured_means_no_posts(): void
    {
        Http::fake();
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/admin/incidents', ['title' => 'Quiet', 'status' => 'warning']);
        Http::assertNothingSent();
    }

    public function test_account_page_shows_link_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/account/telegram')->assertRedirect();
        $code = $user->fresh()->telegram_link_code;

        $this->actingAs($user)->get('/admin/account')->assertOk()->assertSee("/link {$code}");
    }

    public function test_webhook_rejects_bad_secrets(): void
    {
        $this->postJson('/telegram/webhook/wrong', [])->assertNotFound();
        $this->postJson('/telegram/webhook/sekret', [])->assertNotFound(); // header missing
        $this->postJson('/telegram/webhook/sekret', [], ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertNoContent();
    }

    private function tg(array $update)
    {
        return $this->postJson('/telegram/webhook/sekret', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'sekret'])->assertNoContent();
    }

    private function say(string $text, int $chat = 555): void
    {
        $this->tg(['message' => ['text' => $text, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat]]]);
    }

    private function press(string $data, int $chat = 555): void
    {
        $this->tg(['callback_query' => ['id' => 'cb1', 'data' => $data, 'message' => ['message_id' => 9, 'chat' => ['id' => $chat, 'type' => 'private']]]]);
    }

    public function test_telegram_linking_and_authorisation(): void
    {
        Http::fake();

        // Not linked: nothing is created.
        $this->say('/warn Hacker | nope');
        $this->assertDatabaseMissing('incidents', ['title' => 'Hacker']);

        $user = User::factory()->create();
        $user->forceFill(['telegram_link_code' => 'ABCD1234', 'telegram_link_expires_at' => now()->addMinutes(5)])->save();

        $this->say('/link WRONG000');
        $this->assertNull($user->fresh()->telegram_chat_id);

        $this->say('/link ABCD1234');
        $this->assertSame('555', $user->fresh()->telegram_chat_id);
        $this->assertNull($user->fresh()->telegram_link_code);

        // Groups are ignored even for linked users.
        $this->tg(['message' => ['text' => '/warn Group spam', 'chat' => ['id' => 555, 'type' => 'group']]]);
        $this->assertDatabaseMissing('incidents', ['title' => 'Group spam']);
    }

    public function test_telegram_commands_manage_the_site(): void
    {
        Http::fake();
        User::factory()->create(['telegram_chat_id' => '555']);

        $this->say('/warn Protest at Gateway | Roads blocked near the mall');
        $i = Incident::where('title', 'Protest at Gateway')->firstOrFail();
        $this->assertSame('warning', $i->status);
        $this->assertSame('telegram', $i->source);

        // Guided flow.
        $this->say('/new');
        $this->press('new:monitoring');
        $this->say('Crowd gathering');
        $this->say('About 200 people on Edwin Swales');
        $this->say('/skip');
        $this->say('/done');
        $this->assertDatabaseHas('incidents', ['title' => 'Crowd gathering', 'status' => 'monitoring', 'body' => 'About 200 people on Edwin Swales']);

        // Buttons.
        $this->press("inc:{$i->id}:resolved");
        $this->assertSame('resolved', $i->fresh()->status);

        $this->say("/edit {$i->id} Gateway protest ended | Dispersed");
        $this->assertSame('Gateway protest ended', $i->fresh()->title);

        $this->press("inc:{$i->id}:delete!");
        $this->assertModelMissing($i);

        // Level, news, sitrep.
        $this->press('lvl:2');
        $this->assertSame('2', Setting::get('alert_level'));

        $this->say('/news Test release | https://example.com/x | Body text');
        $this->assertDatabaseHas('news_items', ['title' => 'Test release', 'source_url' => 'https://example.com/x', 'body' => 'Body text']);

        $this->say('/sit 1 danger Road closed | Use the M4');
        $this->assertDatabaseHas('report_entries', ['id' => 1, 'status' => 'danger']);
        $this->get('/')->assertSee('Road closed')->assertSee('Use the M4');
    }
}
