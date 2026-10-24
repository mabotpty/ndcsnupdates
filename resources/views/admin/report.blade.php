@extends('layouts.admin', ['title' => 'Situation Report'])

@section('content')
  <div class="a-head">
    <div>
      <h1>Situation Report</h1>
      <p class="lead" style="margin:0">Add each road as its own item: <b>road name</b>, <b>area</b> and a <b>status</b> (🟢 all clear, 🟠 caution, 🔴 avoid). A section with no items shows “No reported issues”.</p>
    </div>
  </div>

  @foreach ($groups as $g)
    <div class="card panel">
      <form method="post" action="{{ route('admin.report.groups.update', $g) }}" class="sit-group-form">
        @csrf @method('PUT')
        <div><label>Section title</label><input type="text" name="title" value="{{ $g->title }}" required></div>
        <div><label>Order</label><input type="number" name="sort" value="{{ $g->sort }}" min="0"></div>
        <button class="btn" type="submit">Save</button>
        <button class="btn btn-danger" type="submit" form="del-g-{{ $g->id }}" onclick="return confirm('Delete this section and every road in it?')">Delete section</button>
      </form>
      <form id="del-g-{{ $g->id }}" method="post" action="{{ route('admin.report.groups.destroy', $g) }}">@csrf @method('DELETE')</form>

      {{-- Add a road --}}
      <form class="sit-row sit-add" method="post" action="{{ route('admin.report.entries.store', $g) }}">
        @csrf
        <span class="dot dot-ok" aria-hidden="true"></span>
        <input type="text" name="road" placeholder="Road name" required maxlength="120" aria-label="Road name">
        <input type="text" name="area" placeholder="Area" maxlength="120" aria-label="Area">
        <select name="status" aria-label="Status">
          @foreach (\App\Models\ReportEntry::STATUSES as $k => $label)<option value="{{ $k }}" @selected($k === 'warning')>{{ \App\Models\ReportEntry::DOTS[$k] }} {{ $label }}</option>@endforeach
        </select>
        <button class="btn btn-primary" type="submit">+ Add road</button>
      </form>

      @forelse ($g->entries as $e)
        <form class="sit-row" method="post" action="{{ route('admin.report.entries.update', $e) }}">
          @csrf @method('PUT')
          <span class="dot dot-{{ $e->status }}" role="img" aria-label="{{ $e->statusLabel() }}" title="{{ $e->statusLabel() }}"></span>
          <input type="text" name="road" value="{{ $e->road }}" required maxlength="120" aria-label="Road name">
          <input type="text" name="area" value="{{ $e->area }}" maxlength="120" placeholder="Area" aria-label="Area">
          <select name="status" aria-label="Status">
            @foreach (\App\Models\ReportEntry::STATUSES as $k => $label)<option value="{{ $k }}" @selected($e->status === $k)>{{ \App\Models\ReportEntry::DOTS[$k] }} {{ $label }}</option>@endforeach
          </select>
          <span class="sit-actions">
            <button class="btn btn-sm" type="submit">Save</button>
            <button class="btn btn-sm btn-danger" type="submit" form="del-e-{{ $e->id }}" onclick="return confirm('Remove {{ addslashes($e->road) }}?')">Remove</button>
          </span>
        </form>
        <form id="del-e-{{ $e->id }}" method="post" action="{{ route('admin.report.entries.destroy', $e) }}">@csrf @method('DELETE')</form>
      @empty
        <p class="row-sub" style="margin:10px 0 0">🟢 No reported issues — nothing listed in this section.</p>
      @endforelse
    </div>
  @endforeach

  <form class="card panel" method="post" action="{{ route('admin.report.groups.store') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
    @csrf
    <div style="flex:1;min-width:220px"><label>New section title</label><input type="text" name="title" required placeholder="e.g. Schools"></div>
    <button class="btn btn-primary" type="submit">Add section</button>
  </form>
@endsection
