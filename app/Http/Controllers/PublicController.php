<?php

namespace App\Http\Controllers;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\ReportGroup;
use App\Models\Setting;
use App\Models\WeatherLevel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class PublicController extends Controller
{
    public function index(): View
    {
        $current = AlertLevel::find((int) Setting::get('alert_level', '0')) ?? AlertLevel::orderBy('level')->first();

        $weatherLevels = WeatherLevel::orderBy('level')->get();
        // The overall weather level is the highest level among warnings that haven't been resolved.
        $weatherNow = (int) Incident::weather()->where('status', '!=', 'resolved')->max('weather_level');

        return view('public.index', [
            'current' => $current,
            'levels' => AlertLevel::orderBy('level')->get(),
            'weatherLevels' => $weatherLevels,
            'weatherCurrent' => $weatherLevels->firstWhere('level', $weatherNow) ?? $weatherLevels->first(),
            'groups' => ReportGroup::with('entries')->orderBy('sort')->orderBy('id')->get(),
            'warnings' => Incident::where('status', 'warning')->newest()->get(),
            'monitoring' => Incident::where('status', 'monitoring')->newest()->get(),
            'resolved' => Incident::visibleResolved()->newest()->get(),
            'updatedAt' => $this->updatedAt(),
            'telegramUrl' => Setting::get('telegram_group_url', 'https://t.me/ndcsncommunityupdates') ?: null,
        ]);
    }

    public function weather(Incident $incident): View
    {
        abort_unless($incident->isWeather(), 404);

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
            'items' => NewsItem::orderByDesc('published_at')->orderByDesc('id')->get(),
            'updatedAt' => $this->updatedAt(),
        ]);
    }

    private function updatedAt(): ?Carbon
    {
        $stamp = Setting::get('content_updated_at');

        return $stamp ? Carbon::parse($stamp) : null;
    }
}
