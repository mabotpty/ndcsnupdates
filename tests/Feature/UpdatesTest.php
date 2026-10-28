<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
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
        $this->get('/news')->assertOk()->assertSee('Latest News');
    }

    public function test_latest_news_keeps_14_days_has_the_note_and_a_whatsapp_fallback(): void
    {
        NewsItem::query()->delete();

        // Nothing there: the message, with the WhatsApp text (a link once the number is set in the admin).
        $this->get('/news')->assertOk()
            ->assertSee('Please note:')->assertSee('only important news/updates regarding safety and security for the last 14 days are posted here')
            ->assertSee('No relevant information available at this time.')->assertSee('send us a WhatsApp')->assertDontSee('wa.me');
        Setting::put('whatsapp_url', 'https://wa.me/27820000000');
        $this->get('/news')->assertSee('<a href="https://wa.me/27820000000"', false);

        // Posts older than 14 days are hidden straight away and removed by the nightly prune.
        NewsItem::create(['title' => 'Fresh post', 'published_at' => now()->subDays(13)]);
        NewsItem::create(['title' => 'Stale post', 'published_at' => now()->subDays(15)]);
        $this->get('/news')->assertSee('Fresh post')->assertDontSee('Stale post')->assertDontSee('No relevant information');
        $this->artisan('news:prune')->expectsOutput('Removed 1 news post older than 14 days.')->assertSuccessful();
        $this->assertDatabaseMissing('news_items', ['title' => 'Stale post']);
        $this->assertDatabaseHas('news_items', ['title' => 'Fresh post']);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'news:prune'));
        $this->assertSame('10 3 * * *', $event->expression);
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
        $this->actingAs($user)->post('/admin/settings', ['resolved_hours' => 48, 'auto_resolve_hours' => 12, 'telegram_group_url' => ''])->assertRedirect();
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

    public function test_bot_publishes_releases_step_by_step(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $this->travelTo(now()->setTime(15, 0));
        User::factory()->create(['telegram_chat_id' => '555']);
        $sayId = fn (string $t, int $id) => $this->tg(['message' => ['message_id' => $id, 'text' => $t, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]]]);

        $this->say('/news');
        $this->say('POLICE UPDATE ON THE MARCH');
        $this->say('not a link');                       // re-asks
        $this->assertDatabaseMissing('news_items', ['title' => 'POLICE UPDATE ON THE MARCH']);
        $this->say('https://www.facebook.com/share/p/abc/');
        $this->say('whenever');                          // re-asks
        $this->say('13:30');

        // Pasted text arrives as separate updates, here out of order; a part at Telegram's size limit joins straight on.
        $first = str_repeat('a', 4096);
        $sayId('third paragraph.', 103);
        $sayId($first, 101);
        $sayId('second paragraph.', 102);
        $this->assertDatabaseMissing('news_items', ['title' => 'POLICE UPDATE ON THE MARCH']);
        $this->say('/done');

        $item = \App\Models\NewsItem::where('title', 'POLICE UPDATE ON THE MARCH')->firstOrFail();
        $this->assertSame('https://www.facebook.com/share/p/abc/', $item->source_url);
        $this->assertSame('facebook.com', $item->source_name);
        $this->assertSame(now()->setTime(13, 30)->toDateTimeString(), $item->published_at->toDateTimeString());
        $this->assertSame($first.'second paragraph.'."\n\n".'third paragraph.', $item->body);
        $this->get('/news')->assertSee('POLICE UPDATE ON THE MARCH');

        // Shared to the group in the agreed format, with the whole text and links.
        $posts = implode("\n", $this->groupPosts());
        $this->assertStringContainsString('SAPS RELEASE', $posts);
        $this->assertStringContainsString('Source: https://www.facebook.com/share/p/abc/', $posts);

        // Everything skippable: link, time and text are optional.
        $this->say('/news');
        $this->say('Bare title');
        $this->say('/skip');
        $this->say('/skip');
        $this->say('/done');
        $bare = \App\Models\NewsItem::where('title', 'Bare title')->firstOrFail();
        $this->assertNull($bare->source_url);
        $this->assertNull($bare->body);
        $this->assertTrue($bare->published_at->diffInSeconds(now(), true) < 5);
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

    public function test_old_open_items_are_resolved_quietly(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');

        $make = function (string $title, string $status, int $hoursOld) {
            $i = Incident::create(['title' => $title, 'status' => $status]);
            $i->forceFill(['created_at' => now()->subHours($hoursOld)])->saveQuietly();

            return $i;
        };
        $old = $make('Old warning', 'warning', 13);
        $oldMon = $make('Old monitoring', 'monitoring', 30);
        $fresh = $make('Fresh warning', 'warning', 11);
        $backdated = Incident::create(['title' => 'Backdated but just posted', 'status' => 'warning', 'published_at' => now()->subDays(2)]);
        $done = $make('Already resolved', 'resolved', 50);

        $this->artisan('incidents:auto-resolve')->assertSuccessful();

        $this->assertSame('resolved', $old->fresh()->status);
        $this->assertNotNull($old->fresh()->resolved_at);
        $this->assertSame('resolved', $oldMon->fresh()->status);
        $this->assertSame('warning', $fresh->fresh()->status);
        $this->assertSame('warning', $backdated->fresh()->status);
        $this->assertSame('resolved', $done->fresh()->status);

        // Quiet: nothing about "RESOLVED" goes to the group.
        $this->assertStringNotContainsString('RESOLVED', implode("\n", $this->groupPosts()));

        // 0 switches it off.
        Setting::put('auto_resolve_hours', '0');
        $again = $make('Ancient', 'warning', 500);
        $this->artisan('incidents:auto-resolve')->assertSuccessful();
        $this->assertSame('warning', $again->fresh()->status);

        // Configurable from the admin dashboard.
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/admin/settings', ['resolved_hours' => 48, 'auto_resolve_hours' => 6, 'telegram_group_url' => ''])->assertRedirect();
        $this->assertSame('6', Setting::get('auto_resolve_hours'));
    }

    public function test_situation_report_is_cleared_every_night_at_3am(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command, 'incidents:auto-resolve')));
        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command, 'report:clear-roads') && $e->expression === '0 3 * * *'));
        $this->assertSame('Africa/Johannesburg', config('app.timezone')); // so 03:00 means South African time
        $this->assertFalse($events->contains(fn ($e) => str_contains($e->command, 'report:clear-areas')));

        // Every road goes; sections stay and fall back to "No reported issues".
        $sectionCount = \App\Models\ReportGroup::count();
        $this->road('Kingsway', 'Amanzimtoti', 'ok');
        $this->road('M4 North', 'Umhlanga', 'danger');
        $this->road('Old Main Road', null, 'warning');
        $this->assertSame(3, \App\Models\ReportEntry::count());

        $this->artisan('report:clear-roads')->expectsOutput('Removed 3 situation report roads.')->assertSuccessful();

        $this->assertSame(0, \App\Models\ReportEntry::count());
        $this->assertSame($sectionCount, \App\Models\ReportGroup::count());
        $this->get('/')->assertDontSee('Kingsway')->assertSee('No reported issues');

        // Running it on an already-empty report is harmless.
        $this->artisan('report:clear-roads')->expectsOutput('Removed 0 situation report roads.')->assertSuccessful();
    }
    private function weather(array $over = []): Incident
    {
        return Incident::create($over + [
            'type' => 'weather', 'status' => 'warning', 'title' => 'Heavy rain expected', 'weather_kind' => 'Heavy rain',
            'weather_level' => 6, 'area' => 'Durban North', 'weather_from' => now()->addHour(), 'weather_to' => now()->addHours(10),
            'narrative' => "Rain of 50mm.\n\nExpect flooding.", 'published_at' => now(),
        ]);
    }

    public function test_weather_levels_follow_the_saws_key(): void
    {
        $l = \App\Models\WeatherLevel::all()->keyBy('level');
        $this->assertSame(['Yellow - Level 1', 'yellow', 'Medium Likelihood, Minor Impact'], [$l[1]->name, $l[1]->colour, $l[1]->description]);
        $this->assertSame(['Yellow - Level 7', 'yellow', 'Very Low Likelihood, Severe Impact'], [$l[7]->name, $l[7]->colour, $l[7]->description]);
        $this->assertSame(['Orange - Level 6', 'orange', 'High Likelihood, Significant Impact'], [$l[6]->name, $l[6]->colour, $l[6]->description]);
        $this->assertSame(['Red - Level 10', 'red', 'High Likelihood, Severe Impact'], [$l[10]->name, $l[10]->colour, $l[10]->description]);
    }

    public function test_weather_shows_under_warnings_with_level_and_a_details_page(): void
    {
        $w = $this->weather(['weather_from' => now()->subHour()]);
        $this->get('/')->assertOk()
            ->assertSee('Current Weather Alert Level')->assertSee('Orange - Level 6')
            ->assertSee('Heavy rain expected')->assertSee('Level 6')->assertSee('Weather')
            ->assertSee($w->weatherUrl());

        $this->get($w->weatherUrl())->assertOk()
            ->assertSee('Heavy rain expected')->assertSee('Heavy rain')->assertSee('Durban North')
            ->assertSee('Level 6')->assertDontSee('of 10')->assertSee('High Likelihood, Significant Impact')->assertSee('Expect flooding.');

        // Not a weather warning → no page.
        $this->get('/weather/'.Incident::where('type', 'incident')->first()->id)->assertNotFound();

        // Overall level = highest unresolved; none = level 0; resolved warnings don't count.
        $this->weather(['title' => 'Gale', 'weather_level' => 9, 'weather_from' => now()->subMinute()]);
        $this->get('/')->assertSee('Orange - Level 9');
        Incident::weather()->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->get('/')->assertSee('No weather warnings');
    }

    private function currentWeatherPill(): string
    {
        preg_match('/id="wx-h">.*?<span class="level-pill"><i><\/i>([^<]*)<\/span>/s', $this->get('/')->getContent(), $m);

        return trim($m[1] ?? '');
    }

    public function test_weather_level_shows_the_current_state_and_future_warnings_as_level_at_time(): void
    {
        $this->travelTo(now()->setTime(8, 0));
        $this->weather(['title' => 'Showers', 'weather_level' => 3, 'weather_from' => now()->subHour(), 'weather_to' => now()->addHours(6)]);
        $gale = $this->weather(['title' => 'Gale', 'weather_level' => 8, 'weather_from' => now()->setTime(14, 0), 'weather_to' => now()->setTime(20, 0)]);

        // Now: the current level is the one in effect (3), the later one is listed as "Level 8 at 14:00".
        $this->get('/')->assertOk()->assertSee('Level 8 at 14:00');
        $this->assertSame('Yellow - Level 3', $this->currentWeatherPill());
        $this->get($gale->weatherUrl())->assertSee('Level 8 at 14:00');

        // Once its start time passes, it becomes the current level on its own.
        $this->travelTo(now()->setTime(14, 5));
        $this->get('/')->assertDontSee('Level 8 at');
        $this->assertSame('Orange - Level 8', $this->currentWeatherPill());
        $this->get($gale->weatherUrl())->assertDontSee('Level 8 at');
    }

    public function test_site_is_an_installable_app(): void
    {
        // Manifest: valid, standalone, with real icons of the sizes it claims (incl. a maskable one).
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('#14407a', $manifest['theme_color']);
        $purposes = [];
        foreach ($manifest['icons'] as $icon) {
            [$w, $h] = getimagesize(public_path(ltrim(strtok($icon['src'], '?'), '/')));
            $this->assertSame($icon['sizes'], "{$w}x{$h}", $icon['src']);
            $purposes[] = $icon['purpose'];
        }
        $this->assertContains('maskable', $purposes);
        $this->assertContains('any', $purposes);
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('icons/apple-touch-icon.png')), 0, 2));
        foreach ($manifest['shortcuts'] as $s) {
            $this->assertStringStartsWith('/', $s['url']);
        }

        // Service worker: never touches the admin site or Telegram webhook, only plain GETs; offline page is precached.
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("indexOf('/admin') === 0", $sw);
        $this->assertStringContainsString("indexOf('/telegram') === 0", $sw);
        $this->assertStringContainsString("req.method !== 'GET'", $sw);
        $this->assertStringContainsString('/offline.html', $sw);
        $this->assertStringContainsString('You\'re offline', file_get_contents(public_path('offline.html')));

        // Every public page links the manifest + icon, registers the worker, and shows the logo in the header.
        foreach (['/', '/weather', '/news'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false)
                ->assertSee('viewport-fit=cover', false)->assertSee("register('/sw.js')", false)
                ->assertSee('class="brand-logo"', false)->assertSee('alt="NDCSN Community Updates logo"', false)
                ->assertSee('/icons/icon-192.png', false);
        }

        // Home page: the "install it as an app" bar sits directly under the Telegram bar, with steps for each device.
        $home = $this->get('/')->assertOk()
            ->assertSeeInOrder(['Get updates instantly', 'Install it as an app', 'All information is acquired'])
            ->assertSee('data-install', false)->assertSee('id="install-help"', false)
            ->assertSee('Add to Home Screen')->assertSee('Install app')->assertSee('install</b> icon', false);
        foreach (['ios', 'android', 'desktop'] as $os) {
            $this->assertStringContainsString('data-os="'.$os.'"', $home->getContent());
        }
        $this->get('/news')->assertDontSee('id="app-bar"', false);
        // The admin site is not part of the app shell.
        $this->actingAs(User::factory()->create())->get('/admin')->assertOk()->assertDontSee('rel="manifest"', false);
    }

    public function test_android_browsers_that_trigger_play_protect_get_no_manifest(): void
    {
        $chrome = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
        $samsung = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36';
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
        $desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

        // Chrome (and iOS / desktop) can install the app; Samsung Internet gets no manifest, so it can't build the flagged wrapper.
        foreach ([$chrome, $iphone, $desktop] as $ua) {
            $this->withHeaders(['User-Agent' => $ua])->get('/')->assertSee('rel="manifest"', false);
        }
        $this->withHeaders(['User-Agent' => $samsung])->get('/')->assertDontSee('rel="manifest"', false)
            ->assertSee('data-os="android-other"', false)->assertSee('package=com.android.chrome', false)->assertSee('Open in Chrome');
    }

    public function test_favicon_and_app_icons_are_the_community_updates_logo(): void
    {
        // favicon.ico holds 16/32/48 PNG entries.
        $ico = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', $ico);
        $this->assertSame([0, 1, 3], [$header['reserved'], $header['type'], $header['count']]);
        $sizes = [];
        for ($i = 0; $i < 3; $i++) {
            $e = unpack('Cw/Ch/Ccolors/Cres/vplanes/vbits/Vlen/Voffset', substr($ico, 6 + 16 * $i, 16));
            $sizes[] = $e['w'];
            $this->assertSame("\x89PNG", substr($ico, $e['offset'], 4));
        }
        $this->assertSame([16, 32, 48], $sizes);

        // The logo is a round badge: "any" icons are transparent at the corners, maskable/iOS tiles are solid brand blue.
        $corner = fn (string $f) => imagecolorat(imagecreatefrompng(public_path("icons/{$f}")), 1, 1);
        $this->assertSame(127, ($corner('icon-512.png') >> 24) & 127, 'any icon keeps the round logo on transparent');
        foreach (['icon-maskable-512.png', 'apple-touch-icon.png'] as $solid) {
            $c = $corner($solid);
            $this->assertSame(0, ($c >> 24) & 127, $solid.' must be opaque');
            $this->assertSame([0x14, 0x40, 0x7a], [($c >> 16) & 255, ($c >> 8) & 255, $c & 255]);
        }
        // Maskable: nothing but background in the corners and edge midpoints (logo stays inside the safe zone).
        $mask = imagecreatefrompng(public_path('icons/icon-maskable-512.png'));
        foreach ([[2, 256], [509, 256], [256, 2], [256, 509]] as [$x, $y]) {
            $this->assertSame(0x14407a, imagecolorat($mask, $x, $y) & 0xFFFFFF);
        }

        // Every page links them (public, admin and login).
        $this->get('/')->assertSee('/favicon.ico', false)->assertSee('favicon-32.png', false)->assertSee('apple-touch-icon.png', false);
        $this->get('/admin/login')->assertSee('/favicon.ico', false);
        $this->actingAs(User::factory()->create())->get('/admin')->assertSee('/favicon.ico', false);
    }

    private function pushSpy(): \App\Services\PushNotifier
    {
        config(['services.webpush.public' => 'BTESTPUBLICKEY', 'services.webpush.private' => 'TESTPRIVATE']);
        $spy = new class extends \App\Services\PushNotifier
        {
            public array $sent = [];

            protected function deliver(\Illuminate\Support\Collection $subscriptions, array $payload): void
            {
                $this->sent[] = ['payload' => $payload, 'endpoints' => $subscriptions->pluck('endpoint')->sort()->values()->all()];
            }

            public function to(string $category, ?string $titleContains = null): array
            {
                return collect($this->sent)->filter(fn ($s) => $s['payload']['category'] === $category && (! $titleContains || str_contains($s['payload']['title'].$s['payload']['body'], $titleContains)))
                    ->pluck('endpoints')->unique(fn ($e) => json_encode($e))->values()->all();
            }
        };
        $this->app->instance(\App\Services\PushNotifier::class, $spy);

        return $spy;
    }

    private function device(string $name, array $categories, int $min = 0): \App\Models\PushSubscription
    {
        $endpoint = "https://fcm.googleapis.com/fcm/send/{$name}";

        return \App\Models\PushSubscription::create([
            'endpoint_hash' => \App\Models\PushSubscription::hashFor($endpoint), 'endpoint' => $endpoint,
            'p256dh' => 'BKey', 'auth' => 'authkey', 'categories' => $categories, 'min_weather_level' => $min,
        ]);
    }

    public function test_push_subscription_endpoints_validate_and_manage_choices(): void
    {
        $good = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
            'categories' => ['weather', 'news'], 'min_weather_level' => 5,
        ];

        // Subscribe, then the same device changes its choices (upsert, not a duplicate).
        $this->postJson('/push/subscribe', $good)->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/push/subscribe', ['categories' => ['warnings']] + $good)->assertOk();
        $this->assertSame(1, \App\Models\PushSubscription::count());
        $this->postJson('/push/status', ['endpoint' => $good['endpoint']])->assertOk()
            ->assertExactJson(['subscribed' => true, 'categories' => ['warnings'], 'min_weather_level' => 5]);
        $this->postJson('/push/status', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/unknown'])->assertExactJson(['subscribed' => false]);

        // Only genuine push services (the server POSTs to this URL), https, valid keys and categories.
        foreach (['http://fcm.googleapis.com/fcm/send/x', 'https://evil.example.com/hook', 'https://googleapis.com.evil.com/x', 'https://169.254.169.254/latest', 'ftp://fcm.googleapis.com/x'] as $bad) {
            $this->postJson('/push/subscribe', ['endpoint' => $bad] + $good)->assertStatus(422)->assertJsonValidationErrors('endpoint');
        }
        foreach (['https://updates.push.services.mozilla.com/wpush/v2/x', 'https://wns2-par02p.notify.windows.com/w/?token=x', 'https://web.push.apple.com/QGx'] as $ok) {
            $this->postJson('/push/subscribe', ['endpoint' => $ok] + $good)->assertOk();
        }
        $this->postJson('/push/subscribe', ['categories' => ['hax']] + $good)->assertStatus(422);
        $this->postJson('/push/subscribe', ['keys' => ['p256dh' => 'bad key!', 'auth' => 'x']] + $good)->assertStatus(422);
        $this->postJson('/push/subscribe', ['min_weather_level' => 11] + $good)->assertStatus(422);

        // Browser rotated its subscription: the new one inherits the old choices.
        $new = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/rotated', 'keys' => $good['keys'], 'old_endpoint' => $good['endpoint']];
        $this->postJson('/push/subscribe', $new)->assertOk()->assertJson(['categories' => ['warnings'], 'min_weather_level' => 5]);
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $good['endpoint']]);

        // Unsubscribe.
        $this->postJson('/push/unsubscribe', ['endpoint' => $new['endpoint']])->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $new['endpoint']]);
    }

    public function test_push_notifications_go_only_to_devices_that_chose_them(): void
    {
        $spy = $this->pushSpy();
        $this->device('warnings-only', ['warnings']);
        $this->device('weather-all', ['weather']);
        $this->device('weather-6plus', ['weather'], 6);
        $this->device('news-level', ['news', 'level']);
        $this->device('resolved-only', ['resolved']);
        $ep = fn (string ...$n) => array_map(fn ($x) => "https://fcm.googleapis.com/fcm/send/{$x}", $n);
        $admin = User::factory()->create();

        // New incident → "warnings" devices only.
        $this->actingAs($admin)->post('/admin/incidents', ['title' => 'Crowd at the circle', 'status' => 'warning', 'body' => 'Roads blocked']);
        $this->assertSame([$ep('warnings-only')], $spy->to('warnings', 'Crowd at the circle'));

        // Weather level 5 → devices with min 0 only; level 8 → also the 6+ device.
        $this->actingAs($admin)->post('/admin/weather', ['title' => 'Showers', 'weather_kind' => 'Heavy rain', 'weather_level' => 5, 'weather_from' => '2026-10-20 10:00', 'weather_to' => '2026-10-20 20:00']);
        $this->actingAs($admin)->post('/admin/weather', ['title' => 'Gale', 'weather_kind' => 'Strong winds', 'weather_level' => 8, 'weather_from' => '2026-10-21 10:00', 'weather_to' => '2026-10-21 20:00']);
        $this->assertSame([$ep('weather-all')], $spy->to('weather', 'Showers'));
        $this->assertSame([$ep('weather-6plus', 'weather-all')], $spy->to('weather', 'Gale'));

        // SAPS release and safety level change → the news/level device.
        $this->actingAs($admin)->post('/admin/news', ['title' => 'SAPS update on the march']);
        $this->actingAs($admin)->post('/admin/level', ['level' => 3]);
        $this->assertSame([$ep('news-level')], $spy->to('news', 'SAPS update'));
        $this->assertSame([$ep('news-level')], $spy->to('level', 'Orange - Level 3'));

        // Resolving is quiet by default; with "alert" it goes to the resolved-only devices.
        $incident = Incident::where('title', 'Crowd at the circle')->firstOrFail();
        $this->actingAs($admin)->post("/admin/incidents/{$incident->id}/status", ['status' => 'resolved']);
        $this->assertSame([], $spy->to('resolved'));
        $other = Incident::create(['title' => 'Second one', 'status' => 'warning']);
        $this->actingAs($admin)->post("/admin/incidents/{$other->id}/status", ['status' => 'resolved', 'announce' => 1]);
        $this->assertSame([$ep('resolved-only')], $spy->to('resolved', 'Second one'));

        // Payload carries a tap-through link (weather → its own page, news → releases).
        $gale = collect($spy->sent)->first(fn ($s) => str_contains($s['payload']['title'].$s['payload']['body'], 'Gale'));
        $this->assertStringContainsString('/weather/20261021-Level8', $gale['payload']['url']);
        $this->assertStringContainsString('/news', collect($spy->sent)->first(fn ($s) => $s['payload']['category'] === 'news')['payload']['url']);
    }

    public function test_real_delivery_signs_encrypts_and_prunes_dead_devices(): void
    {
        // Throwaway VAPID pair (test only) and RFC 8291's example device keys, so real encryption runs.
        config([
            'services.webpush.public' => 'BPC8qlNrrhA3lbAbmnoLnLtp9EtmOHBLo8HoYD2R60v2qBxpQgwBR_5CgSxNRRVdbNGoWjM2PgG1oxf_4qrt6ek',
            'services.webpush.private' => 'UPXE2y-KpxSWpDRVeX-zh0lryQiB1SJ-OOlgoZyuqmo',
            'services.webpush.subject' => 'https://updates.ndcsn.org.za',
        ]);
        $device = fn (string $name) => \App\Models\PushSubscription::create([
            'endpoint_hash' => \App\Models\PushSubscription::hashFor("https://fcm.googleapis.com/fcm/send/{$name}"),
            'endpoint' => "https://fcm.googleapis.com/fcm/send/{$name}",
            'p256dh' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'auth' => 'BTBZMqHH6r4Tts7J_aSIgg', 'categories' => ['warnings'],
        ]);
        [$ok, $gone, $broken] = [$device('ok'), $device('gone'), $device('broken')];

        $requests = [];
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(201),   // delivered
            new \GuzzleHttp\Psr7\Response(410),   // subscription no longer exists
            new \GuzzleHttp\Psr7\Response(500),   // push service trouble
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($requests));
        $notifier = new class(new \GuzzleHttp\Client(['handler' => $stack])) extends \App\Services\PushNotifier
        {
            public function __construct(private \Psr\Http\Client\ClientInterface $client) {}

            protected function httpClient(): \Psr\Http\Client\ClientInterface
            {
                return $this->client;
            }
        };

        $notifier->send('warnings', ['title' => 'Test', 'body' => 'Hello', 'url' => '/', 'category' => 'warnings']);

        $this->assertCount(3, $requests);
        $first = $requests[0]['request'];
        $this->assertSame('aes128gcm', $first->getHeaderLine('Content-Encoding'));           // payload encrypted
        $this->assertStringStartsWith('vapid t=', $first->getHeaderLine('Authorization'));    // VAPID-signed
        $this->assertSame('high', $first->getHeaderLine('Urgency'));
        $this->assertNotEmpty((string) $first->getBody());
        $this->assertStringNotContainsString('Hello', (string) $first->getBody());           // not plaintext

        // 201 → recorded; 410 → device removed; 500 → kept, failure counted.
        $this->assertNotNull($ok->fresh()->last_sent_at);
        $this->assertModelMissing($gone);
        $this->assertSame(1, $broken->fresh()->failures);
    }

    public function test_push_is_off_until_keys_exist_and_ui_follows(): void
    {
        config(['services.webpush.public' => null, 'services.webpush.private' => null]);
        $this->device('someone', ['warnings']);

        $notifier = new \App\Services\PushNotifier;
        $this->assertFalse($notifier->enabled());
        $this->actingAs(User::factory()->create())->post('/admin/incidents', ['title' => 'No keys', 'status' => 'warning'])->assertRedirect(); // must not blow up
        $this->get('/')->assertDontSee('<meta name="vapid-public-key"', false)->assertDontSee('id="notify-dialog"', false);

        config(['services.webpush.public' => 'BTESTPUBLICKEY', 'services.webpush.private' => 'x']);
        $this->get('/')->assertSee('<meta name="vapid-public-key" content="BTESTPUBLICKEY">', false)
            ->assertSee('id="notify-btn"', false)->assertSee('New safety warnings and monitoring items')
            ->assertSee('Weather warnings')->assertSee('Latest news')->assertSee('resolved alerts');

        // Service worker shows the notification and opens the right page on tap.
        $sw = file_get_contents(public_path('sw.js'));
        foreach (["addEventListener('push'", "addEventListener('notificationclick'", 'showNotification', 'openWindow', "addEventListener('pushsubscriptionchange'"] as $needle) {
            $this->assertStringContainsString($needle, $sw);
        }
        $this->assertFileExists(public_path('icons/badge-96.png'));
    }

    private function road(string $road, ?string $area, string $status = 'warning', ?int $group = null): \App\Models\ReportEntry
    {
        return \App\Models\ReportGroup::findOrFail($group ?? \App\Models\ReportGroup::orderBy('sort')->value('id'))
            ->entries()->create(['road' => $road, 'area' => $area, 'status' => $status]);
    }

    public function test_situation_report_shows_each_road_with_a_coloured_dot(): void
    {
        // Empty sections say so.
        $this->get('/')->assertSee('No reported issues');

        $a = $this->road('Umhlanga Rocks Drive', 'Umhlanga', 'danger');
        $b = $this->road('Kingsway', 'Amanzimtoti', 'ok');
        $this->road('Old Main Road', null, 'warning');

        $html = $this->get('/')->assertOk()
            ->assertSee('Umhlanga Rocks Drive')->assertSee('Umhlanga')->assertSee('Kingsway')->assertSee('Old Main Road')
            ->assertSee('dot dot-danger', false)->assertSee('dot dot-ok', false)->assertSee('dot dot-warning', false)
            ->assertSee('aria-label="Avoid"', false)->assertSee('aria-label="Caution"', false)->assertSee('aria-label="All clear"', false)
            ->getContent();
        // Newest first, and that section no longer claims "No reported issues".
        $this->assertLessThan(strpos($html, 'Kingsway'), strpos($html, 'Old Main Road'));
        $this->assertLessThan(strpos($html, 'Umhlanga Rocks Drive'), strpos($html, 'Kingsway'));
        // Only the section that is still empty says "No reported issues".
        $this->assertSame(1, substr_count($html, 'No reported issues'));
    }

    public function test_admin_adds_updates_and_removes_roads_individually(): void
    {
        $admin = User::factory()->create();
        $group = \App\Models\ReportGroup::orderBy('sort')->firstOrFail();

        $this->actingAs($admin)->get('/admin/report')->assertOk()->assertSee('Add road');

        // Add: road name + area + status (road is required, status must be a known one).
        $this->actingAs($admin)->post("/admin/report/groups/{$group->id}/entries", ['road' => 'M4 North', 'area' => 'Umhlanga', 'status' => 'danger'])->assertRedirect();
        $this->actingAs($admin)->post("/admin/report/groups/{$group->id}/entries", ['road' => '', 'status' => 'ok'])->assertSessionHasErrors('road');
        $this->actingAs($admin)->post("/admin/report/groups/{$group->id}/entries", ['road' => 'X', 'status' => 'purple'])->assertSessionHasErrors('status');
        $this->actingAs($admin)->post("/admin/report/groups/{$group->id}/entries", ['road' => 'N2', 'area' => '', 'status' => 'ok']);

        $m4 = \App\Models\ReportEntry::where('road', 'M4 North')->firstOrFail();
        $n2 = \App\Models\ReportEntry::where('road', 'N2')->firstOrFail();
        $this->assertSame(['Umhlanga', 'danger'], [$m4->area, $m4->status]);
        $this->assertNull($n2->area);   // blank area is stored as nothing

        // Update one without touching the other.
        $this->actingAs($admin)->put("/admin/report/entries/{$m4->id}", ['road' => 'M4 North (Umhlanga)', 'area' => 'Umhlanga', 'status' => 'warning'])->assertRedirect();
        $this->assertSame(['M4 North (Umhlanga)', 'warning'], [$m4->fresh()->road, $m4->fresh()->status]);
        $this->assertSame('ok', $n2->fresh()->status);
        $this->get('/')->assertSee('M4 North (Umhlanga)')->assertSee('N2');

        // Remove just one.
        $this->actingAs($admin)->delete("/admin/report/entries/{$m4->id}")->assertRedirect();
        $this->assertModelMissing($m4);
        $this->assertModelExists($n2);
        $this->get('/')->assertDontSee('M4 North')->assertSee('N2');

        // Sections still work; an old multi-line "lines" field is gone.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('report_entries', 'lines'));
        $this->actingAs($admin)->post('/admin/report/groups', ['title' => 'Schools'])->assertRedirect();
        $this->assertDatabaseHas('report_groups', ['title' => 'Schools']);
    }

    public function test_old_multi_line_entries_become_individual_roads_when_migrating(): void
    {
        $migration = require base_path('database/migrations/2026_10_24_000001_situation_report_items.php');
        $group = \App\Models\ReportGroup::orderBy('sort')->value('id');

        // Put the table back the way it was (lines, no road), then add legacy rows.
        $migration->down();
        \Illuminate\Support\Facades\DB::table('report_entries')->insert([
            ['report_group_id' => $group, 'area' => 'Northern Area', 'status' => 'danger', 'lines' => "M4 closed at Umhlanga\nUse the N2", 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['report_group_id' => $group, 'area' => 'Durban City', 'status' => 'ok', 'lines' => 'No reported issues', 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['report_group_id' => $group, 'area' => null, 'status' => 'warning', 'lines' => "Slow traffic\n\nno reported issues\nRoadworks on Kingsway", 'sort' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $roads = \App\Models\ReportEntry::orderBy('id')->get(['road', 'area', 'status'])->map(fn ($e) => [$e->road, $e->area, $e->status])->all();
        $this->assertSame([
            ['M4 closed at Umhlanga', 'Northern Area', 'danger'],
            ['Use the N2', 'Northern Area', 'danger'],
            ['Slow traffic', null, 'warning'],
            ['Roadworks on Kingsway', null, 'warning'],
        ], $roads); // placeholders ("No reported issues") dropped, real information kept
    }

    public function test_bot_manages_situation_report_roads(): void
    {
        Http::fake();
        User::factory()->create(['telegram_chat_id' => '555']);
        $transport = \App\Models\ReportGroup::orderBy('sort')->firstOrFail();
        $existing = $this->road('Kingsway', 'Amanzimtoti', 'ok');

        // /sitrep: a menu with every road as a button, plus add / section / clear buttons.
        $this->say('/sitrep');
        $menu = json_encode($this->sentTo('sendMessage'));
        foreach (["sr:e:{$existing->id}", "sr:a:{$transport->id}", 'sr:g', 'sr:r', 'Kingsway', 'Transport Routes', 'Add a road'] as $needle) {
            $this->assertStringContainsString($needle, $menu);
        }

        // Add a road step by step: name → area → status button.
        $this->press("sr:a:{$transport->id}");
        $this->say('Umhlanga Rocks Drive');
        $this->say('Umhlanga');
        $this->press('sr:as:danger');
        $road = \App\Models\ReportEntry::where('road', 'Umhlanga Rocks Drive')->firstOrFail();
        $this->assertSame([$transport->id, 'Umhlanga', 'danger'], [$road->report_group_id, $road->area, $road->status]);
        $this->get('/')->assertSee('Umhlanga Rocks Drive')->assertSee('dot dot-danger', false);

        // Skip the area; typing the status word works too.
        $this->press("sr:a:{$transport->id}");
        $this->say('Old Main Road');
        $this->say('/skip');
        $this->say('orange');
        $om = \App\Models\ReportEntry::where('road', 'Old Main Road')->firstOrFail();
        $this->assertNull($om->area);
        $this->assertSame('warning', $om->status);

        // Nonsense instead of a status creates nothing.
        $this->press("sr:a:{$transport->id}");
        $this->say('Ghost Road');
        $this->say('/skip');
        $this->say('banana');
        $this->assertDatabaseMissing('report_entries', ['road' => 'Ghost Road']);
        $this->say('/cancel');

        // Change status with the buttons; rename and re-area it.
        $this->press("sr:st:{$road->id}:ok");
        $this->assertSame('ok', $road->fresh()->status);
        $this->press("sr:t:{$road->id}");
        $this->say('Umhlanga Rocks Dr');
        $this->press("sr:ar:{$road->id}");
        $this->say('/skip');
        $this->assertSame(['Umhlanga Rocks Dr', null], [$road->fresh()->road, $road->fresh()->area]);

        // Remove one road: asks first, removes only that one.
        $this->press("sr:d:{$om->id}");
        $this->assertModelExists($om);
        $this->press("sr:dy:{$om->id}");
        $this->assertModelMissing($om);
        $this->assertModelExists($road);

        // New section, then a road in it.
        $this->press('sr:g');
        $this->say('Schools');
        $schools = \App\Models\ReportGroup::where('title', 'Schools')->firstOrFail();
        $this->press("sr:a:{$schools->id}");
        $this->say('Durban North Primary');
        $this->say('Durban North');
        $this->press('sr:as:ok');
        $this->assertDatabaseHas('report_entries', ['road' => 'Durban North Primary', 'report_group_id' => $schools->id]);

        // Quick commands.
        $this->say('/road Mount Edgecombe Rd | Mount Edgecombe | red');
        $q = \App\Models\ReportEntry::where('road', 'Mount Edgecombe Rd')->firstOrFail();
        $this->assertSame([$transport->id, 'Mount Edgecombe', 'danger'], [$q->report_group_id, $q->area, $q->status]);
        $this->say('/road Lunch Rd | | green | school');
        $this->assertSame($schools->id, \App\Models\ReportEntry::where('road', 'Lunch Rd')->value('report_group_id'));
        $this->say('/road Default Rd');
        $this->assertSame('warning', \App\Models\ReportEntry::where('road', 'Default Rd')->value('status'));
        $this->say('/road Bad Rd | | purple');
        $this->assertDatabaseMissing('report_entries', ['road' => 'Bad Rd']);
        $this->say("/sit {$q->id} orange");
        $this->assertSame('warning', $q->fresh()->status);
        $this->say("/rmroad {$q->id}");
        $this->assertModelMissing($q);

        // Clear everything: asks first; sections stay and show "No reported issues".
        $this->press('sr:r');
        $this->assertGreaterThan(0, \App\Models\ReportEntry::count());
        $this->press('sr:ry');
        $this->assertSame(0, \App\Models\ReportEntry::count());
        $this->assertGreaterThan(0, \App\Models\ReportGroup::count());
        $this->get('/')->assertSee('No reported issues');
    }

    public function test_weather_links_are_date_and_level_with_the_from_time_on_a_clash(): void
    {
        $at = fn (string $from, int $level) => $this->weather(['weather_from' => $from, 'weather_to' => '2026-10-20 23:00', 'weather_level' => $level]);

        $first = $at('2026-10-16 08:30', 6);
        $this->assertSame('20261016-Level6', $first->slug);
        $this->assertSame(url('/weather/20261016-Level6'), $first->weatherUrl());

        // Same date and level → append the from time.
        $second = $at('2026-10-16 14:00', 6);
        $this->assertSame('20261016-Level6-1400', $second->slug);
        $this->assertStringEndsWith('/weather/20261016-Level6-1400', $second->weatherUrl());

        // Different level or different date → no clash, no time.
        $this->assertSame('20261016-Level9', $at('2026-10-16 09:00', 9)->slug);
        $this->assertSame('20261017-Level6', $at('2026-10-17 14:00', 6)->slug);

        // Identical date, level and time (rare) still gets a unique link.
        $this->assertSame('20261016-Level6-1400-2', $at('2026-10-16 14:00', 6)->slug);

        // Pages resolve by link; the home page and group post use it.
        $this->get('/weather/20261016-Level6-1400')->assertOk()->assertSee('Heavy rain expected');
        $this->get('/weather/20261016-Level6')->assertOk();
        $this->get('/weather/20991231-Level1')->assertNotFound();
        $this->get('/')->assertSee('/weather/20261016-Level6-1400', false);

        // Stable: editing the warning later doesn't change a link that may already be shared.
        $first->update(['weather_level' => 3, 'weather_from' => '2026-11-01 10:00']);
        $this->assertSame('20261016-Level6', $first->fresh()->slug);

        // Old numeric links redirect permanently to the new one; non-weather ids stay 404.
        $this->get('/weather/'.$second->id)->assertRedirect($second->weatherUrl())->assertStatus(301);
    }

    public function test_header_nav_and_weather_alerts_page(): void
    {
        // Header: no "NDCSN" eyebrow, new refresh wording, three tabs, no SAPS line in the yellow box.
        $home = $this->get('/')->assertOk()
            ->assertSee('This page refreshes automatically every minute.')
            ->assertDontSee('updates automatically')
            ->assertDontSee('class="eyebrow"', false)
            ->assertDontSee('Latest releases from the SAPS can be found here')
            ->assertSeeInOrder(['Safety Status', 'Weather Alerts', 'Latest News']);
        $this->assertSame(1, substr_count($home->getContent(), 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#aria-current="page" >Safety Status#', $home->getContent());

        // Weather Alerts page: empty state, tab highlighted.
        $empty = $this->get('/weather')->assertOk()->assertSee('No active weather alerts at this time.');
        $this->assertMatchesRegularExpression('#aria-current="page" >Weather Alerts#', $empty->getContent());

        // Active warnings in full (newest first); resolved ones are not listed; tab shows a count.
        $a = $this->weather(['title' => 'Older warning', 'published_at' => now()->subHours(3), 'narrative' => 'Older narrative text.']);
        $this->weather(['title' => 'Newer warning', 'weather_level' => 9, 'narrative' => 'Newer narrative text.']);
        $this->weather(['title' => 'Gone warning', 'status' => 'resolved', 'resolved_at' => now()]);

        $page = $this->get('/weather')->assertOk()
            ->assertSeeInOrder(['Newer warning', 'Newer narrative text.', 'Older warning', 'Older narrative text.'])
            ->assertSee('Level 9')->assertDontSee('of 10')->assertSee('Durban North')->assertDontSee('Gone warning');
        $this->assertStringContainsString('<span class="nav-count">2</span>', $page->getContent());

        // The single-warning page keeps the Weather Alerts tab highlighted and links back to the list.
        $one = $this->get($a->weatherUrl())->assertOk()->assertSee('All weather alerts');
        $this->assertMatchesRegularExpression('#aria-current="page" >Weather Alerts#', $one->getContent());

        // Other pages highlight their own tab.
        $news = $this->get('/news')->assertOk();
        $this->assertMatchesRegularExpression('#aria-current="page" >Latest News#', $news->getContent());
    }

    public function test_newest_warning_first_with_weather_mixed_in(): void
    {
        $this->weather(['title' => 'Weather older', 'published_at' => now()->subHours(2)]);
        Incident::create(['title' => 'Incident newest', 'status' => 'warning', 'published_at' => now()]);
        $this->weather(['title' => 'Weather middle', 'published_at' => now()->subHour()]);

        $this->get('/')->assertSeeInOrder(['Incident newest', 'Weather middle', 'Weather older']);
    }

    public function test_weather_warnings_resolve_quietly_one_hour_after_their_to_time(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');

        $ended30 = $this->weather(['title' => 'Ended 30m ago', 'weather_from' => now()->subHours(5), 'weather_to' => now()->subMinutes(30)]);
        $ended90 = $this->weather(['title' => 'Ended 90m ago', 'weather_from' => now()->subHours(9), 'weather_to' => now()->subMinutes(90)]);
        $running = $this->weather(['title' => 'Still running', 'weather_from' => now()->subHours(2), 'weather_to' => now()->addHours(3)]);
        // Old (posted 40h ago) but still running: the 12h rule must not touch weather.
        $running->forceFill(['created_at' => now()->subHours(40)])->saveQuietly();

        $this->artisan('incidents:auto-resolve')->assertSuccessful();

        $this->assertSame('warning', $ended30->fresh()->status);
        $this->assertSame('resolved', $ended90->fresh()->status);
        $this->assertSame('warning', $running->fresh()->status);
        $this->assertStringNotContainsString('RESOLVED', implode("\n", $this->groupPosts()));
    }

    public function test_admin_can_post_weather_and_it_is_announced(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $admin = User::factory()->create();

        foreach (['/admin/weather', '/admin/weather/create'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // Validation: to must be after from, level 1-10.
        $bad = ['title' => 'x', 'weather_kind' => 'Hail', 'weather_level' => 11, 'weather_from' => '2026-10-17 10:00', 'weather_to' => '2026-10-17 09:00'];
        $this->actingAs($admin)->post('/admin/weather', $bad)->assertSessionHasErrors(['weather_level', 'weather_to']);

        $this->actingAs($admin)->post('/admin/weather', [
            'title' => 'Damaging winds', 'weather_kind' => 'Strong winds', 'weather_level' => 5, 'area' => 'Umhlanga',
            'weather_from' => '2026-10-17 10:00', 'weather_to' => '2026-10-17 20:00', 'narrative' => 'Gusts up to 80km/h.',
        ])->assertRedirect('/admin/weather');

        $w = Incident::weather()->where('title', 'Damaging winds')->firstOrFail();
        $this->assertSame('warning', $w->status);
        $this->assertSame(5, $w->weather_level);

        $post = implode("\n", $this->groupPosts());
        foreach (['WEATHER WARNING', 'Damaging winds', 'Orange - Level 5', 'Strong winds', 'Area:</b> Umhlanga', 'Gusts up to 80km/h.', 'Emergency Services can be contacted on 031 361 0000 or 112 from a cellphone.', $w->weatherUrl()] as $needle) {
            $this->assertStringContainsString($needle, $post);
        }

        // Edit page, and weather items edit through the weather form from the generic incident screens.
        $this->actingAs($admin)->get("/admin/weather/{$w->id}/edit")->assertOk();
        $this->actingAs($admin)->get("/admin/incidents/{$w->id}/edit")->assertRedirect("/admin/weather/{$w->id}/edit");
        $this->actingAs($admin)->get('/admin/incidents')->assertOk()->assertSee('Damaging winds');
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_weather_group_post_has_the_agreed_format(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/admin/weather', [
            'title' => 'Flash flooding', 'weather_kind' => 'Flooding', 'weather_level' => 10, 'area' => 'Phoenix & Verulam',
            'weather_from' => '2026-10-17 10:00', 'weather_to' => '2026-10-17 20:00', 'narrative' => 'River levels rising.',
        ]);
        $w = Incident::weather()->firstOrFail();

        $this->assertSame(
            "⛈ <b>WEATHER WARNING</b>\n"
            ."<b>Flash flooding</b> (Flooding)\n\n"
            ."🔴 <b>Level:</b> Red - Level 10 — High Likelihood, Severe Impact\n"
            ."🕒 <b>From/To:</b> Sat 17 Oct, 10:00 → Sat 17 Oct, 20:00\n"
            ."📍 <b>Area:</b> Phoenix &amp; Verulam\n"
            ."📝 <b>Narrative:</b> River levels rising.\n\n"
            ."<i>Emergency Services can be contacted on 031 361 0000 or 112 from a cellphone.</i>\n\n"
            .'🌐 '.$w->weatherUrl(),
            $this->groupPosts()[0],
        );

        // Level colours: SAWS 1-4/7 yellow, 5/6/8/9 orange, 10 red.
        $dots = collect([1 => '🟡', 5 => '🟠', 7 => '🟡', 9 => '🟠', 10 => '🔴'])->map(function ($dot, $lvl) {
            $x = $this->weather(['title' => "L{$lvl}", 'weather_level' => $lvl]);

            return str_contains((new \ReflectionMethod(\App\Services\Announcer::class, 'weatherText'))->invoke(app(\App\Services\Announcer::class), $x), "{$dot} <b>Level:</b>");
        });
        $this->assertTrue($dots->every(fn ($ok) => $ok));
    }

    public function test_bot_posts_weather_step_by_step(): void
    {
        Http::fake();
        Setting::put('telegram_group_chat_id', '-100123');
        $this->travelTo(now()->setTime(9, 0));
        User::factory()->create(['telegram_chat_id' => '555']);

        $this->say('/new');
        $this->press('new:weather');
        $this->say('Thunderstorms this evening');
        $this->press('wxt:1');                 // Thunderstorms
        $this->say('eleven');                  // re-asks
        $this->press('wxl:7');                 // level 7
        $this->say('Durban North, Umhlanga');
        $this->say('nonsense');                // re-asks
        $this->say('17:00');
        $this->say('16:00');                   // before the start → rolls to tomorrow
        $this->say('Severe thunderstorms with hail.');
        $this->say('/done');

        $w = Incident::weather()->where('title', 'Thunderstorms this evening')->firstOrFail();
        $this->assertSame('Thunderstorms', $w->weather_kind);
        $this->assertSame(7, $w->weather_level);
        $this->assertSame('Durban North, Umhlanga', $w->area);
        $this->assertSame(now()->setTime(17, 0)->toDateTimeString(), $w->weather_from->toDateTimeString());
        $this->assertSame(now()->addDay()->setTime(16, 0)->toDateTimeString(), $w->weather_to->toDateTimeString());
        $this->assertSame('Severe thunderstorms with hail.', $w->narrative);
        $this->assertSame('telegram', $w->source);
        $this->assertStringContainsString('🟡 <b>Level:</b> Yellow - Level 7', implode("\n", $this->groupPosts())); // SAWS level 7 is yellow

        // Typed type + /skip for area and from; no narrative.
        $this->say('/weather');
        $this->say('Dense fog');
        $this->press('wxt:10');                // Other
        $this->say('Fog bank');
        $this->say('2');
        $this->say('/skip');
        $this->say('/skip');
        $this->say('tomorrow 06:00');
        $this->say('/done');
        $fog = Incident::weather()->where('title', 'Dense fog')->firstOrFail();
        $this->assertSame('Fog bank', $fog->weather_kind);
        $this->assertNull($fog->area);
        $this->assertNull($fog->narrative);
        $this->assertSame(now()->addDay()->setTime(6, 0)->toDateTimeString(), $fog->weather_to->toDateTimeString());
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

        $this->say('/road Umhlanga Rocks Dr | Umhlanga | red');
        $this->assertDatabaseHas('report_entries', ['road' => 'Umhlanga Rocks Dr', 'status' => 'danger']);
        $this->get('/')->assertSee('Umhlanga Rocks Dr');
    }
}
