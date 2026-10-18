<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $fillable = ['endpoint_hash', 'endpoint', 'p256dh', 'auth', 'categories', 'min_weather_level', 'user_agent', 'failures', 'last_sent_at'];

    protected function casts(): array
    {
        return ['categories' => 'array', 'last_sent_at' => 'datetime'];
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
