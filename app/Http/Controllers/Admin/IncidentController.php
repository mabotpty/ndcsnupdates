<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('status');

        return view('admin.incidents.index', [
            'incidents' => Incident::query()
                ->when(isset(Incident::STATUSES[$filter]), fn ($q) => $q->where('status', $filter))
                ->newest()->paginate(25)->withQueryString(),
            'filter' => $filter,
            'groupOn' => (bool) Setting::get('telegram_group_chat_id'),
        ]);
    }

    public function create()
    {
        return view('admin.incidents.form', [
            'incident' => new Incident(['status' => 'monitoring', 'published_at' => now()]),
            'groupOn' => (bool) Setting::get('telegram_group_chat_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Incident::create($this->validated($request) + ['source' => 'web', 'created_by' => $request->user()->name]);

        return redirect()->route('admin.incidents.index')->with('status', 'Posted. It is live on the site now.');
    }

    public function edit(Incident $incident)
    {
        return view('admin.incidents.form', ['incident' => $incident, 'groupOn' => (bool) Setting::get('telegram_group_chat_id')]);
    }

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $incident->announce = $request->boolean('announce');
        $incident->update($this->validated($request));

        return redirect()->route('admin.incidents.index')->with('status', 'Saved.');
    }

    public function status(Request $request, Incident $incident): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Incident::STATUSES))]]);
        $incident->announce = $request->boolean('announce');
        $incident->update($data);

        return back()->with('status', "Moved to {$data['status']}.");
    }

    public function destroy(Incident $incident): RedirectResponse
    {
        $incident->delete();

        return back()->with('status', 'Deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:5000',
            'status' => ['required', Rule::in(array_keys(Incident::STATUSES))],
            'published_at' => 'nullable|date',
        ]);
        $data['published_at'] = $data['published_at'] ?? now();

        return $data;
    }
}
