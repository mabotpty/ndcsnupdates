@extends('layouts.admin', ['title' => $w->exists ? 'Edit weather warning' : 'New weather warning'])

@section('content')
  <div class="a-head"><h1>{{ $w->exists ? 'Edit weather warning' : 'New weather warning' }}</h1></div>

  <form class="card panel" style="max-width:820px" method="post" action="{{ $w->exists ? route('admin.weather.update', $w) : route('admin.weather.store') }}">
    @csrf
    @if ($w->exists) @method('PUT') @endif

    <div class="field">
      <label for="title">Short title</label>
      <input id="title" type="text" name="title" value="{{ old('title', $w->title) }}" required maxlength="120" autofocus placeholder="e.g. Heavy rain and flooding expected">
    </div>

    <div class="grid2" style="gap:16px">
      <div class="field">
        <label for="kind">Type of weather</label>
        <input id="kind" type="text" name="weather_kind" list="kinds" value="{{ old('weather_kind', $w->weather_kind) }}" required maxlength="60">
        <datalist id="kinds">@foreach (\App\Models\Incident::WEATHER_KINDS as $k)<option value="{{ $k }}">@endforeach</datalist>
        <p class="hint">Pick one or type your own.</p>
      </div>
      <div class="field">
        <label for="lvl">Alert level (1–10)</label>
        <select id="lvl" name="weather_level" required>
          <option value="">Choose…</option>
          @foreach ($levels as $l)
            <option value="{{ $l->level }}" @selected((int) old('weather_level', $w->weather_level) === $l->level)>{{ $l->level }} — {{ $l->name }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="field">
      <label for="area">Area</label>
      <input id="area" type="text" name="area" value="{{ old('area', $w->area) }}" maxlength="255" placeholder="e.g. Durban North, Umhlanga, Phoenix">
    </div>

    <div class="grid2" style="gap:16px">
      <div class="field">
        <label for="from">From</label>
        <input id="from" type="datetime-local" name="weather_from" value="{{ old('weather_from', $w->weather_from?->format('Y-m-d\TH:i')) }}" required>
      </div>
      <div class="field">
        <label for="to">To</label>
        <input id="to" type="datetime-local" name="weather_to" value="{{ old('weather_to', $w->weather_to?->format('Y-m-d\TH:i')) }}" required>
        <p class="hint">The warning resolves itself quietly 1 hour after this.</p>
      </div>
    </div>

    <div class="field">
      <label for="narr">Detailed narrative</label>
      <textarea id="narr" name="narrative" style="min-height:200px">{{ old('narrative', $w->narrative) }}</textarea>
    </div>

    @if ($w->exists)
      <div class="field">
        <label for="st">Status</label>
        <select id="st" name="status">
          <option value="warning" @selected(old('status', $w->status) !== 'resolved')>Active</option>
          <option value="resolved" @selected(old('status', $w->status) === 'resolved')>Resolved</option>
        </select>
      </div>
      @if ($groupOn)
        <div class="field">
          <label style="font-weight:500"><input type="checkbox" name="announce" value="1"> If this is being marked <b>Resolved</b>, also send an alert to the Telegram group</label>
        </div>
      @endif
    @endif

    <button class="btn btn-primary" type="submit">{{ $w->exists ? 'Save' : 'Post to site' }}</button>
    <a class="btn" href="{{ route('admin.weather.index') }}">Cancel</a>
  </form>
@endsection
