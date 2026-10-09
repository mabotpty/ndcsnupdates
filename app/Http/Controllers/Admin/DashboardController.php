<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        return view('admin.dashboard', [
            'levels' => AlertLevel::orderBy('level')->get(),
            'currentLevel' => (int) Setting::get('alert_level', '0'),
            'resolvedHours' => (int) Setting::get('resolved_hours', '48'),
            'telegramUrl' => Setting::get('telegram_group_url', 'https://t.me/ndcsncommunityupdates'),
            'open' => Incident::whereIn('status', ['warning', 'monitoring'])->newest()->get(),
            'recentResolved' => Incident::where('status', 'resolved')->newest()->limit(5)->get(),
            'newsCount' => NewsItem::count(),
        ]);
    }

    public function setLevel(Request $request): RedirectResponse
    {
        $data = $request->validate(['level' => 'required|integer|exists:alert_levels,level']);
        Setting::put('alert_level', (string) $data['level']);
        Setting::put('content_updated_at', now()->toIso8601String());

        return back()->with('status', 'Alert level updated.');
    }

    public function updateLevel(Request $request, AlertLevel $level): RedirectResponse
    {
        $level->update($request->validate([
            'name' => 'required|string|max:60',
            'description' => 'required|string|max:255',
        ]));

        return back()->with('status', "Level {$level->level} text updated.");
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'resolved_hours' => 'required|integer|min:1|max:8760',
            'telegram_group_url' => 'nullable|url:https|max:255',
        ]);
        Setting::put('resolved_hours', (string) $data['resolved_hours']);
        Setting::put('telegram_group_url', $data['telegram_group_url'] ?? '');
        Setting::put('content_updated_at', now()->toIso8601String());

        return back()->with('status', 'Settings saved.');
    }
}
