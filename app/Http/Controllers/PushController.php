<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Anonymous opt-in endpoints for browser push (no accounts; the device's own endpoint is the identity). */
class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', function ($attribute, $value, $fail) {
                if (! PushNotifier::allowedEndpoint($value)) {
                    $fail('That push service is not supported.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'keys.auth' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'categories' => 'array',
            'categories.*' => ['string', Rule::in(array_keys(PushNotifier::CATEGORIES))],
            'min_weather_level' => 'nullable|integer|between:0,10',
            'old_endpoint' => 'nullable|string|max:2000',
        ]);

        $categories = array_values(array_unique($data['categories'] ?? []));
        $minLevel = (int) ($data['min_weather_level'] ?? 0);

        // The browser rotated its subscription: carry the old choices over.
        if (! empty($data['old_endpoint']) && ! $request->has('categories')) {
            $old = PushSubscription::where('endpoint_hash', PushSubscription::hashFor($data['old_endpoint']))->first();
            if ($old) {
                $categories = $old->categories;
                $minLevel = $old->min_weather_level;
                $old->delete();
            }
        }

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'categories' => $categories,
                'min_weather_level' => $minLevel,
                'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 255),
                'failures' => 0,
            ],
        );

        return response()->json(['ok' => true, 'categories' => $categories, 'min_weather_level' => $minLevel]);
    }

    public function status(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');
        $sub = $endpoint !== '' ? PushSubscription::where('endpoint_hash', PushSubscription::hashFor($endpoint))->first() : null;

        return response()->json($sub
            ? ['subscribed' => true, 'categories' => $sub->categories, 'min_weather_level' => (int) $sub->min_weather_level]
            : ['subscribed' => false]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');
        if ($endpoint !== '') {
            PushSubscription::where('endpoint_hash', PushSubscription::hashFor($endpoint))->delete();
        }

        return response()->json(['ok' => true]);
    }
}
