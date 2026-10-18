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
        <h2>Phone notifications</h2>
        @if ($push['enabled'])
          <p style="margin-top:0"><b>{{ $push['total'] }}</b> device{{ $push['total'] === 1 ? '' : 's' }} subscribed.</p>
          @if ($push['total'])
            <ul class="row-sub" style="margin:0;padding-left:18px">
              @foreach (\App\Services\PushNotifier::CATEGORIES as $key => $label)
                <li>{{ $label }}: {{ $push['byCategory'][$key] ?? 0 }}</li>
              @endforeach
            </ul>
          @endif
          <p class="hint">Visitors turn this on with the 🔔 Notifications button on the site. Alerts are sent automatically when you post, change the level or add a release.</p>
        @else
          <p class="row-sub" style="margin:0">Not set up yet. Run <code>php artisan push:keys</code> on the server to switch it on.</p>
        @endif
      </div>

      <div class="card panel">
        <h2>Weather alert levels</h2>
        <p class="hint" style="margin-top:-8px">The public weather level is the highest level among active weather warnings. Edit the wording of each level below.</p>
        <details>
          <summary style="cursor:pointer;font-weight:700">Edit weather level wording</summary>
          @foreach ($weatherLevels as $l)
            <form method="post" action="{{ route('admin.weather-level.update', $l->level) }}" class="entry" style="margin-top:12px">
              @csrf @method('PUT')
              <div class="inline" style="display:grid;grid-template-columns:1fr 140px;gap:10px">
                <div class="field"><label>Name (level {{ $l->level }})</label><input type="text" name="name" value="{{ $l->name }}" required></div>
                <div class="field"><label>Colour</label>
                  <select name="colour">@foreach (['green', 'yellow', 'orange', 'red'] as $c)<option value="{{ $c }}" @selected($l->colour === $c)>{{ ucfirst($c) }}</option>@endforeach</select>
                </div>
              </div>
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
            <label for="ar">Quietly resolve open items after (hours)</label>
            <input id="ar" type="number" name="auto_resolve_hours" value="{{ $autoResolveHours }}" min="0" max="720" required>
            <p class="hint">Warnings and monitoring items still open this long after being posted are resolved automatically, without alerting the Telegram group. 0 turns it off.</p>
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
                <form method="post" action="{{ route('admin.incidents.status', $i) }}">@csrf<input type="hidden" name="status" value="resolved"><button class="btn btn-sm" type="submit">✓ Resolve{{ $groupOn ? ' quietly' : '' }}</button>@if ($groupOn) <button class="btn btn-sm" type="submit" name="announce" value="1" title="Also post to the Telegram group">📣 Resolve + alert group</button>@endif</form>
                <form method="post" action="{{ route('admin.incidents.status', $i) }}">@csrf<input type="hidden" name="status" value="{{ $i->status === 'warning' ? 'monitoring' : 'warning' }}"><button class="btn btn-sm" type="submit">Move to {{ $i->status === 'warning' ? 'monitoring' : 'warning' }}</button></form>
              </div>
              <a class="btn btn-sm" href="{{ $i->isWeather() ? route('admin.weather.edit', $i) : route('admin.incidents.edit', $i) }}">Edit</a>
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
