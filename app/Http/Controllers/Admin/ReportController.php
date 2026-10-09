<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReportEntry;
use App\Models\ReportGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index()
    {
        return view('admin.report', [
            'groups' => ReportGroup::with('entries')->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:255']);
        ReportGroup::create($data + ['sort' => (int) ReportGroup::max('sort') + 1]);

        return back()->with('status', 'Section added.');
    }

    public function updateGroup(Request $request, ReportGroup $group): RedirectResponse
    {
        $group->update($request->validate(['title' => 'required|string|max:255', 'sort' => 'required|integer|min:0']));

        return back()->with('status', 'Section saved.');
    }

    public function destroyGroup(ReportGroup $group): RedirectResponse
    {
        $group->delete();

        return back()->with('status', 'Section deleted.');
    }

    public function storeEntry(Request $request, ReportGroup $group): RedirectResponse
    {
        $group->entries()->create($this->entryData($request) + ['sort' => (int) $group->entries()->max('sort') + 1]);

        return back()->with('status', 'Entry added.');
    }

    public function updateEntry(Request $request, ReportEntry $entry): RedirectResponse
    {
        $entry->update($this->entryData($request));

        return back()->with('status', 'Entry saved.');
    }

    public function destroyEntry(ReportEntry $entry): RedirectResponse
    {
        $entry->delete();

        return back()->with('status', 'Entry deleted.');
    }

    private function entryData(Request $request): array
    {
        $data = $request->validate([
            'area' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(array_keys(ReportEntry::STATUSES))],
            'lines' => 'required|string|max:3000',
            'sort' => 'nullable|integer|min:0',
        ]);

        return array_filter($data, fn ($v) => $v !== null) + ['area' => null];
    }
}
