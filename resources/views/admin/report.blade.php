@extends('layouts.admin', ['title' => 'Situation Report'])

@section('content')
  <div class="a-head"><div><h1>Situation Report</h1><p class="lead" style="margin:0">The left-hand column of the site. Each section has one or more areas; put one bullet per line.</p></div></div>

  @foreach ($groups as $g)
    <div class="card panel">
      <form method="post" action="{{ route('admin.report.groups.update', $g) }}" style="display:grid;grid-template-columns:1fr 90px auto auto;gap:10px;align-items:end;margin-bottom:16px">
        @csrf @method('PUT')
        <div><label>Section title</label><input type="text" name="title" value="{{ $g->title }}" required></div>
        <div><label>Order</label><input type="number" name="sort" value="{{ $g->sort }}" min="0"></div>
        <button class="btn" type="submit">Save</button>
        <button class="btn btn-danger" type="submit" form="del-g-{{ $g->id }}" onclick="return confirm('Delete this section and all its entries?')">Delete</button>
      </form>
      <form id="del-g-{{ $g->id }}" method="post" action="{{ route('admin.report.groups.destroy', $g) }}">@csrf @method('DELETE')</form>

      @foreach ($g->entries as $e)
        <form class="entry" method="post" action="{{ route('admin.report.entries.update', $e) }}">
          @csrf @method('PUT')
          <div class="inline">
            <div><label>Area (optional)</label><input type="text" name="area" value="{{ $e->area }}" placeholder="e.g. Northern Area"></div>
            <div><label>Status</label>
              <select name="status">
                @foreach (\App\Models\ReportEntry::STATUSES as $k => $label)<option value="{{ $k }}" @selected($e->status === $k)>{{ $label }}</option>@endforeach
              </select>
            </div>
            <div><label>Order</label><input type="number" name="sort" value="{{ $e->sort }}" min="0"></div>
          </div>
          <textarea name="lines" required>{{ $e->lines }}</textarea>
          <div class="bar">
            <button class="btn btn-primary btn-sm" type="submit">Save entry</button>
            <button class="btn btn-sm btn-danger" type="submit" form="del-e-{{ $e->id }}" onclick="return confirm('Delete this entry?')">Delete</button>
          </div>
        </form>
        <form id="del-e-{{ $e->id }}" method="post" action="{{ route('admin.report.entries.destroy', $e) }}">@csrf @method('DELETE')</form>
      @endforeach

      <details>
        <summary style="cursor:pointer;font-weight:700">+ Add an area to “{{ $g->title }}”</summary>
        <form class="entry" style="margin-top:12px" method="post" action="{{ route('admin.report.entries.store', $g) }}">
          @csrf
          <div class="inline">
            <div><label>Area (optional)</label><input type="text" name="area"></div>
            <div><label>Status</label>
              <select name="status">@foreach (\App\Models\ReportEntry::STATUSES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div></div>
          </div>
          <textarea name="lines" required placeholder="No reported issues"></textarea>
          <div class="bar"><button class="btn btn-primary btn-sm" type="submit">Add</button></div>
        </form>
      </details>
    </div>
  @endforeach

  <form class="card panel" method="post" action="{{ route('admin.report.groups.store') }}" style="display:flex;gap:10px;align-items:end">
    @csrf
    <div style="flex:1"><label>New section title</label><input type="text" name="title" required placeholder="e.g. Schools"></div>
    <button class="btn btn-primary" type="submit">Add section</button>
  </form>
@endsection
