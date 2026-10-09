<?php

namespace App\Http\Controllers;

use App\Models\AlertLevel;
use App\Models\Incident;
use App\Models\NewsItem;
use App\Models\ReportGroup;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class PublicController extends Controller
{
    public function index(): View
    {
        $current = AlertLevel::find((int) Setting::get('alert_level', '0')) ?? AlertLevel::orderBy('level')->first();

        return view('public.index', [
            'current' => $current,
            'levels' => AlertLevel::orderBy('level')->get(),
            'groups' => ReportGroup::with('entries')->orderBy('sort')->orderBy('id')->get(),
            'warnings' => Incident::where('status', 'warning')->newest()->get(),
            'monitoring' => Incident::where('status', 'monitoring')->newest()->get(),
            'resolved' => Incident::visibleResolved()->newest()->get(),
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
