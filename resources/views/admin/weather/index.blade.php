@extends('layouts.admin', ['title' => 'Weather warnings'])

@section('content')
  <div class="a-head">
    <div><h1>Weather warnings</h1><p class="lead" style="margin:0">They show under Warnings on the site and resolve quietly 1 hour after their "to" time.</p></div>
    <a class="btn btn-primary" href="{{ route('admin.weather.create') }}">+ New weather warning</a>
  </div>

  <div class="card">
    <table class="t">
      <thead><tr><th>Warning</th><th>Level</th><th>Window</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @forelse ($warnings as $w)
          <tr>
            <td><div class="row-title">⛈ {{ $w->title }}</div><div class="row-sub">{{ $w->weather_kind }}@if ($w->area) · {{ $w->area }}@endif</div></td>
            <td><span class="badge b-warning">Level {{ $w->weather_level }}</span></td>
            <td class="row-sub">{{ $w->weather_from?->format('D j M, H:i') }}<br>→ {{ $w->weather_to?->format('D j M, H:i') }}</td>
            <td>
              @if ($w->status === 'resolved')<span class="badge b-success">Resolved</span>
              @else<span class="badge b-danger">{{ ucfirst($w->weatherPhase()) }}</span>@endif
            </td>
            <td>
              <div class="actions">
                @if ($w->status !== 'resolved')
                  <form method="post" action="{{ route('admin.incidents.status', $w) }}">@csrf<input type="hidden" name="status" value="resolved"><button class="btn btn-sm" type="submit">Resolve{{ $groupOn ? ' quietly' : '' }}</button>@if ($groupOn) <button class="btn btn-sm" type="submit" name="announce" value="1">📣 + alert group</button>@endif</form>
                @endif
                <a class="btn btn-sm" href="{{ route('admin.weather.edit', $w) }}">Edit</a>
                <a class="btn btn-sm" href="{{ $w->weatherUrl() }}" target="_blank">View ↗</a>
                <form method="post" action="{{ route('admin.weather.destroy', $w) }}" onsubmit="return confirm('Delete this weather warning?')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="row-sub">No weather warnings.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="pager">{{ $warnings->links() }}</div>
@endsection
