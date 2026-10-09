<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    public function index()
    {
        return view('admin.news.index', [
            'items' => NewsItem::orderByDesc('published_at')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function create()
    {
        return view('admin.news.form', ['item' => new NewsItem(['published_at' => now()])]);
    }

    public function store(Request $request): RedirectResponse
    {
        NewsItem::create($this->validated($request));

        return redirect()->route('admin.news.index')->with('status', 'Added.');
    }

    public function edit(NewsItem $item)
    {
        return view('admin.news.form', compact('item'));
    }

    public function update(Request $request, NewsItem $item): RedirectResponse
    {
        $item->update($this->validated($request));

        return redirect()->route('admin.news.index')->with('status', 'Saved.');
    }

    public function destroy(NewsItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('status', 'Deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'source_name' => 'nullable|string|max:100',
            'source_url' => 'nullable|url:http,https|max:500',
            'published_at' => 'nullable|date',
            'body' => 'nullable|string|max:20000',
        ]);
        $data['published_at'] = $data['published_at'] ?? now();

        return $data;
    }
}
