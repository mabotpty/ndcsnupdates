<?php

namespace App\Http\Controllers;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\ReportGroup;
use App\Models\Setting;
use App\Models\WeatherLevel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class PublicController extends Controller
{
    public function index(): View
    {
        $current = AlertLevel::find((int) Setting::get('alert_level', '0')) ?? AlertLevel::orderBy('level')->first();

        $weatherLevels = WeatherLevel::orderBy('level')->get();
        // The current weather level is the highest among warnings in effect right now; later warnings
        // are listed as "Level x at time" and take over automatically once their start time passes.
        $weatherNow = (int) Incident::weatherInEffect()->max('weather_level');

        return view('public.index', [
            'current' => $current,
            'levels' => AlertLevel::orderBy('level')->get(),
            'weatherLevels' => $weatherLevels,
            'weatherCurrent' => $weatherLevels->firstWhere('level', $weatherNow) ?? $weatherLevels->first(),
            'weatherUpcoming' => Incident::weatherUpcoming()->get(),
            'groups' => ReportGroup::with('entries')->orderBy('sort')->orderBy('id')->get(),
            'warnings' => Incident::where('status', 'warning')->newest()->get(),
            'monitoring' => Incident::where('status', 'monitoring')->newest()->get(),
            'resolved' => Incident::visibleResolved()->newest()->get(),
            'updatedAt' => $this->updatedAt(),
            'telegramUrl' => Setting::get('telegram_group_url', 'https://t.me/ndcsncommunityupdates') ?: null,
        ]);
    }

    /** Every unresolved weather warning, in full. */
    public function weatherIndex(): View
    {
        return view('public.weather-index', [
            'warnings' => Incident::weather()->where('status', '!=', 'resolved')->newest()->get(),
            'weatherLevels' => WeatherLevel::orderBy('level')->get(),
            'updatedAt' => $this->updatedAt(),
        ]);
    }

    /** Looked up by its link (e.g. 20261016-Level6); an old numeric id redirects to the link. */
    public function weather(string $ref): View|RedirectResponse
    {
        $incident = Incident::weather()->where('slug', $ref)->first();

        if (! $incident && ctype_digit($ref) && ($byId = Incident::weather()->find((int) $ref))) {
            return redirect()->to($byId->weatherUrl(), 301);
        }

        abort_unless($incident, 404);

        return view('public.weather', [
            'w' => $incident,
            'level' => WeatherLevel::find((int) $incident->weather_level),
            'updatedAt' => $this->updatedAt(),
            'telegramUrl' => Setting::get('telegram_group_url', 'https://t.me/ndcsncommunityupdates') ?: null,
        ]);
    }

    public function news(): View
    {
        return view('public.news', [
            'items' => NewsItem::where('published_at', '>=', now()->subDays(NewsItem::KEEP_DAYS))->orderByDesc('published_at')->orderByDesc('id')->get(),
            'whatsappUrl' => Setting::get('whatsapp_url') ?: null,
            'updatedAt' => $this->updatedAt(),
        ]);
    }

    private function updatedAt(): ?Carbon
    {
        $stamp = Setting::get('content_updated_at');

        return $stamp ? Carbon::parse($stamp) : null;
    }
}
