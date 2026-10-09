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
