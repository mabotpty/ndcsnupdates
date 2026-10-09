@extends('layouts.admin', ['title' => $item->exists ? 'Edit release' : 'Add release'])

@section('content')
  <div class="a-head"><h1>{{ $item->exists ? 'Edit release' : 'Add release' }}</h1></div>

  <form class="card panel" style="max-width:820px" method="post" action="{{ $item->exists ? route('admin.news.update', $item) : route('admin.news.store') }}">
    @csrf
    @if ($item->exists) @method('PUT') @endif

    <div class="field"><label for="title">Title</label><input id="title" type="text" name="title" value="{{ old('title', $item->title) }}" required maxlength="255" autofocus></div>
    <div class="grid2" style="gap:16px">
      <div class="field"><label for="sn">Source name</label><input id="sn" type="text" name="source_name" value="{{ old('source_name', $item->source_name) }}" placeholder="Facebook"></div>
      <div class="field"><label for="su">Source link</label><input id="su" type="url" name="source_url" value="{{ old('source_url', $item->source_url) }}" placeholder="https://"></div>
    </div>
    <div class="field">
      <label for="pub">Date and time of release</label>
      <input id="pub" type="datetime-local" name="published_at" value="{{ old('published_at', ($item->published_at ?? now())->format('Y-m-d\TH:i')) }}">
    </div>
    <div class="field"><label for="body">Text</label><textarea id="body" name="body" style="min-height:240px">{{ old('body', $item->body) }}</textarea></div>
    <button class="btn btn-primary" type="submit">{{ $item->exists ? 'Save' : 'Publish' }}</button>
    <a class="btn" href="{{ route('admin.news.index') }}">Cancel</a>
  </form>
@endsection
