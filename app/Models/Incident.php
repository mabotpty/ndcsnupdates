<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Incident extends Model
{
    use TouchesSite;

    public const STATUSES = [
        'warning' => 'Warning',
        'monitoring' => 'Monitoring',
        'resolved' => 'Resolved',
    ];

    /**
     * Set before resolving an incident to also post "resolved" in the Telegram
     * group. Not persisted; resolving is quiet unless this is true.
     */
    public bool $announce = false;

    public const WEATHER_KINDS = [
        'Heavy rain', 'Thunderstorms', 'Strong winds', 'Hail', 'Flooding',
        'Fog', 'Extreme heat', 'Extreme cold', 'Fire danger', 'Rough seas', 'Other',
    ];

    public function isWeather(): bool
    {
        return $this->type === 'weather';
    }

    /** Only weather warnings (the "weather incident type"). */
    public function scopeWeather(Builder $query): Builder
    {
        return $query->where('type', 'weather');
    }

    /** upcoming | active | expired, from the warning's from/to window. */
    public function weatherPhase(): string
    {
        if ($this->weather_from && $this->weather_from->isFuture()) {
            return 'upcoming';
        }

        return $this->weather_to && $this->weather_to->isPast() ? 'expired' : 'active';
    }

    /** "Level 6", or "Level 6 at 14:00" while the warning is still in the future (date added if not today). */
    public function weatherLevelLabel(): string
    {
        $label = 'Level '.(int) $this->weather_level;

        if ($this->weatherPhase() !== 'upcoming') {
            return $label;
        }

        return $label.' at '.$this->weather_from->format($this->weather_from->isToday() ? 'H:i' : 'D j M, H:i');
    }

    /** Unresolved warnings whose window has started and not yet ended: these set the current level. */
    public function scopeWeatherInEffect(Builder $query): Builder
    {
        return $query->weather()->where('status', '!=', 'resolved')
            ->where('weather_from', '<=', now())
            ->where('weather_to', '>', now());
    }

    /** Unresolved warnings that start later, soonest first. */
    public function scopeWeatherUpcoming(Builder $query): Builder
    {
        return $query->weather()->where('status', '!=', 'resolved')
            ->where('weather_from', '>', now())
            ->orderBy('weather_from');
    }

    protected $fillable = ['title', 'body', 'status', 'published_at', 'resolved_at', 'source', 'created_by', 'media', 'type', 'weather_kind', 'weather_level', 'area', 'weather_from', 'weather_to', 'narrative'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'resolved_at' => 'datetime', 'media' => 'array', 'weather_from' => 'datetime', 'weather_to' => 'datetime'];
    }

    /**
     * Public link for a weather warning: <from date>-Level<n>, e.g. 20261016-Level6.
     * If that is already taken, the from time is appended (20261016-Level6-1400),
     * and a counter after that in the unlikely event of an identical time.
     * Set once at creation so links that were shared never change.
     */
    public static function makeWeatherSlug(?Carbon $from, int $level): string
    {
        $from ??= now();
        $base = $from->format('Ymd').'-Level'.$level;

        $slug = $base;
        if (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$from->format('Hi');
            for ($n = 2; static::where('slug', $slug)->exists(); $n++) {
                $slug = $base.'-'.$from->format('Hi').'-'.$n;
            }
        }

        return $slug;
    }

    public function weatherUrl(): string
    {
        return route('weather', $this->slug ?? $this->getKey());
    }

    protected static function booted(): void
    {
        static::creating(function (Incident $incident) {
            if ($incident->type === 'weather' && ! $incident->slug) {
                $incident->slug = static::makeWeatherSlug($incident->weather_from, (int) $incident->weather_level);
            }
        });

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
