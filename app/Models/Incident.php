<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    use TouchesSite;

    public const STATUSES = [
        'warning' => 'Warning',
        'monitoring' => 'Monitoring',
        'resolved' => 'Resolved',
    ];

    protected $fillable = ['title', 'body', 'status', 'published_at', 'resolved_at', 'source', 'created_by', 'media'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'resolved_at' => 'datetime', 'media' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Incident $incident) {
            if ($incident->status === 'resolved' && ! $incident->resolved_at) {
                $incident->resolved_at = now();
            } elseif ($incident->status !== 'resolved') {
                $incident->resolved_at = null;
            }
        });
    }

    /** Newest first. */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /** Resolved items only stay on the public page for a while. */
    public function scopeVisibleResolved(Builder $query): Builder
    {
        $hours = (int) Setting::get('resolved_hours', '48');

        return $query->where('status', 'resolved')
            ->where('resolved_at', '>=', now()->subHours($hours));
    }
}
