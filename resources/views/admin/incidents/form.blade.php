@extends('layouts.admin', ['title' => $incident->exists ? 'Edit incident' : 'New incident'])

@section('content')
  <div class="a-head"><h1>{{ $incident->exists ? 'Edit incident' : 'New incident' }}</h1></div>

  <form class="card panel" style="max-width:760px" method="post" action="{{ $incident->exists ? route('admin.incidents.update', $incident) : route('admin.incidents.store') }}">
    @csrf
    @if ($incident->exists) @method('PUT') @endif

    <div class="field">
      <label for="title">Title</label>
      <input id="title" type="text" name="title" value="{{ old('title', $incident->title) }}" required maxlength="255" autofocus>
    </div>
    <div class="field">
      <label for="body">Details</label>
      <textarea id="body" name="body">{{ old('body', $incident->body) }}</textarea>
    </div>
    <div class="grid2" style="gap:16px">
      <div class="field">
        <label for="status">Where does it show?</label>
        <select id="status" name="status">
          <option value="warning" @selected(old('status', $incident->status) === 'warning')>Warnings</option>
          <option value="monitoring" @selected(old('status', $incident->status) === 'monitoring')>Monitoring / Minor Issues</option>
          <option value="resolved" @selected(old('status', $incident->status) === 'resolved')>Resolved</option>
        </select>
      </div>
      <div class="field">
        <label for="pub">Posted at</label>
        <input id="pub" type="datetime-local" name="published_at" value="{{ old('published_at', ($incident->published_at ?? now())->format('Y-m-d\TH:i')) }}">
        <p class="hint">Newest first. Changing this reorders it.</p>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">{{ $incident->exists ? 'Save' : 'Post to site' }}</button>
    <a class="btn" href="{{ route('admin.incidents.index') }}">Cancel</a>
  </form>
@endsection
