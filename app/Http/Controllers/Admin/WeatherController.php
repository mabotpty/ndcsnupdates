<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\WeatherLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WeatherController extends Controller
{
    public function index()
    {
        return view('admin.weather.index', [
            'warnings' => Incident::weather()->newest()->paginate(25),
            'groupOn' => (bool) Setting::get('telegram_group_chat_id'),
        ]);
    }

    public function create()
    {
        return view('admin.weather.form', [
            'w' => new Incident(['type' => 'weather', 'status' => 'warning', 'weather_from' => now(), 'weather_to' => now()->addHours(12)]),
            'levels' => WeatherLevel::where('level', '>', 0)->orderBy('level')->get(),
            'groupOn' => (bool) Setting::get('telegram_group_chat_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Incident::create($this->validated($request) + [
            'type' => 'weather',
            'status' => 'warning',
            'published_at' => now(),
            'source' => 'web',
            'created_by' => $request->user()->name,
        ]);

        return redirect()->route('admin.weather.index')->with('status', 'Weather warning posted.');
    }

    public function edit(Incident $incident)
    {
        abort_unless($incident->isWeather(), 404);

        return view('admin.weather.form', [
            'w' => $incident,
            'levels' => WeatherLevel::where('level', '>', 0)->orderBy('level')->get(),
            'groupOn' => (bool) Setting::get('telegram_group_chat_id'),
        ]);
    }

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($incident->isWeather(), 404);

        $data = $this->validated($request);
        $data['status'] = $request->validate(['status' => ['required', Rule::in(['warning', 'resolved'])]])['status'];

        $incident->announce = $request->boolean('announce');
        $incident->update($data);

        return redirect()->route('admin.weather.index')->with('status', 'Saved.');
    }

    public function destroy(Incident $incident): RedirectResponse
    {
        abort_unless($incident->isWeather(), 404);
        $incident->delete();

        return back()->with('status', 'Deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:120',
            'weather_kind' => 'required|string|max:60',
            'weather_level' => 'required|integer|between:1,10',
            'area' => 'nullable|string|max:255',
            'weather_from' => 'required|date',
            'weather_to' => 'required|date|after:weather_from',
            'narrative' => 'nullable|string|max:10000',
        ]);
    }
}
