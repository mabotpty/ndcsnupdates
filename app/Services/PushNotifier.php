<?php

namespace App\Services;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push to phones/browsers that opted in. Each device chooses which categories
 * it wants (and a minimum weather level), so sending is filtered per subscription.
 */
class PushNotifier
{
    /** key => label shown in the "Notifications" dialog */
    public const CATEGORIES = [
        'warnings' => '⚠️ New safety warnings and monitoring items',
        'weather' => '⛈ Weather warnings',
        'level' => '🚦 Safety alert level changes',
        'news' => '📰 SAPS releases',
        'resolved' => '✅ All clear / resolved alerts',
    ];

    /** Only real push services may be registered (the server POSTs to this URL, so it must not be arbitrary). */
    public const PUSH_HOST_SUFFIXES = ['googleapis.com', 'mozilla.com', 'windows.com', 'apple.com'];

    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        foreach (self::PUSH_HOST_SUFFIXES as $suffix) {
            if ($parts['host'] === $suffix || str_ends_with($parts['host'], '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public function enabled(): bool
    {
        return (bool) (config('services.webpush.public') && config('services.webpush.private'));
    }

    /**
     * Queue a notification for everyone who wants this category. Sent after the response
     * so a slow push service never delays the page that triggered it.
     */
    public function notify(string $category, string $title, string $body, string $url, ?int $weatherLevel = null, ?string $tag = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $payload = [
            'title' => $title,
            'body' => mb_strimwidth(trim($body), 0, 180, '…'),
            'url' => $url,
            'tag' => $tag,
            'category' => $category,
        ];

        dispatch(function () use ($category, $payload, $weatherLevel) {
            try {
                app(PushNotifier::class)->send($category, $payload, $weatherLevel);
            } catch (Throwable $e) {
                Log::warning('Push notification failed', ['message' => $e->getMessage()]);
            }
        })->afterResponse();
    }

    public function send(string $category, array $payload, ?int $weatherLevel = null): void
    {
        $subscriptions = PushSubscription::query()->get()->filter(
            fn (PushSubscription $s) => in_array($category, $s->categories ?? [], true)
                && ($weatherLevel === null || $weatherLevel >= (int) $s->min_weather_level)
        )->values();

        if ($subscriptions->isNotEmpty()) {
            $this->deliver($subscriptions, $payload);
        }
    }

    /** Overridable so tests can point at a mock push service. */
    protected function httpClient(): ClientInterface
    {
        return new Client(['timeout' => 10, 'connect_timeout' => 5]);
    }

    /** @param Collection<int, PushSubscription> $subscriptions */
    protected function deliver(Collection $subscriptions, array $payload): void
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public'),
                'privateKey' => config('services.webpush.private'),
            ],
        ], ['TTL' => 6 * 3600, 'urgency' => 'high'], $this->httpClient());

        $byEndpoint = $subscriptions->keyBy('endpoint');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $s) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint,
                'publicKey' => $s->p256dh,
                'authToken' => $s->auth,
                'contentEncoding' => 'aes128gcm',
            ]), $json);
        }

        foreach ($webPush->flush() as $report) {
            $sub = $byEndpoint->get($report->getEndpoint());
            if (! $sub) {
                continue;
            }

            if ($report->isSuccess()) {
                $sub->update(['failures' => 0, 'last_sent_at' => now()]);
            } elseif ($report->isSubscriptionExpired()) {
                $sub->delete(); // the user removed it or the browser dropped it
            } elseif ($sub->failures >= 4) {
                $sub->delete();
            } else {
                $sub->increment('failures');
            }
        }
    }
}
