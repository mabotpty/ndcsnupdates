<?php

namespace App\Http\Controllers;

use App\Services\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot, string $secret): Response
    {
        $expected = (string) config('services.telegram.secret');

        abort_unless(
            $expected !== ''
            && hash_equals($expected, $secret)
            && hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            404,
        );

        try {
            $bot->handleUpdate($request->json()->all());
        } catch (Throwable $e) {
            // Always ack with 2xx so Telegram doesn't retry a failing update forever.
            Log::error('Telegram webhook error', ['message' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
        }

        return response()->noContent();
    }
}
