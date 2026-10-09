@extends('layouts.admin', ['title' => 'Dashboard'])

@section('content')
  <div class="a-head">
    <div><h1>Dashboard</h1><p class="lead" style="margin:0">Changes go live on the site immediately.</p></div>
    <a class="btn btn-primary" href="{{ route('admin.incidents.create') }}">+ New incident</a>
  </div>

  <div class="grid2">
    <div>
      <div class="card panel">
        <h2>Current safety alert level</h2>
        <div class="levels">
          @foreach ($levels as $l)
            <form method="post" action="{{ route('admin.level') }}">
              @csrf
              <input type="hidden" name="level" value="{{ $l->level }}">
              <button type="submit" @class(['lv-btn', 'on' => $l->level === $currentLevel])>
                <span class="sw sw-{{ $l->colour }}"></span>
                <span><b>{{ $l->name }}</b><small>{{ $l->description }}</small></span>
              </button>
            </form>
          @endforeach
        </div>

        <details style="margin-top:18px">
          <summary style="cursor:pointer;font-weight:700">Edit level wording</summary>
          @foreach ($levels as $l)
            <form method="post" action="{{ route('admin.level.update', $l->level) }}" class="entry" style="margin-top:12px">
              @csrf @method('PUT')
              <div class="field"><label>Name (level {{ $l->level }})</label><input type="text" name="name" value="{{ $l->name }}" required></div>
              <div class="field"><label>Description</label><input type="text" name="description" value="{{ $l->description }}" required></div>
              <button class="btn btn-sm" type="submit">Save</button>
            </form>
          @endforeach
        </details>
      </div>

      <div class="card panel">
        <h2>Settings</h2>
        <form method="post" action="{{ route('admin.settings') }}">
          @csrf
          <div class="field">
            <label for="rh">Keep resolved items on the site for (hours)</label>
            <input id="rh" type="number" name="resolved_hours" value="{{ $resolvedHours }}" min="1" required>
            <p class="hint">After this, resolved items drop off the public page (they stay here).</p>
          </div>
          <div class="field">
            <label for="tg">Public Telegram group link</label>
            <input id="tg" type="url" name="telegram_group_url" value="{{ $telegramUrl }}" placeholder="https://t.me/...">
            <p class="hint">Shown as a "Join on Telegram" banner on the home page. Leave empty to hide it.</p>
          </div>
          <button class="btn btn-primary" type="submit">Save</button>
        </form>
      </div>
    </div>

    <div>
      <div class="card panel">
        <h2>Open items <span class="row-sub">({{ $open->count() }})</span></h2>
        @forelse ($open as $i)
          <div class="entry">
            <div class="row-title">{{ $i->title }}</div>
            <div class="row-sub">
              <span class="badge {{ $i->status === 'warning' ? 'b-danger' : 'b-warning' }}">{{ ucfirst($i->status) }}</span>
              {{ $i->published_at->format('D j M, H:i') }} · via {{ $i->source }}
            </div>
            <div class="bar">
              <div class="actions">
                <form method="post" action="{{ route('admin.incidents.status', $i) }}">@csrf<input type="hidden" name="status" value="resolved"><button class="btn btn-sm" type="submit">✓ Resolve</button></form>
                <form method="post" action="{{ route('admin.incidents.status', $i) }}">@csrf<input type="hidden" name="status" value="{{ $i->status === 'warning' ? 'monitoring' : 'warning' }}"><button class="btn btn-sm" type="submit">Move to {{ $i->status === 'warning' ? 'monitoring' : 'warning' }}</button></form>
              </div>
              <a class="btn btn-sm" href="{{ route('admin.incidents.edit', $i) }}">Edit</a>
            </div>
          </div>
        @empty
          <p class="row-sub">Nothing open. All clear.</p>
        @endforelse
      </div>

      <div class="card panel">
        <h2>Recently resolved</h2>
        @forelse ($recentResolved as $i)
          <div style="padding:8px 0;border-bottom:1px solid var(--border)">
            <span class="row-title">{{ $i->title }}</span>
            <div class="row-sub">Resolved {{ $i->resolved_at->format('D j M, H:i') }}</div>
          </div>
        @empty
          <p class="row-sub">Nothing yet.</p>
        @endforelse
      </div>
    </div>
  </div>
@endsection
