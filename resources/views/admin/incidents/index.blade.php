@extends('layouts.admin', ['title' => 'Incidents'])

@section('content')
  <div class="a-head">
    <h1>Incidents</h1>
    <a class="btn btn-primary" href="{{ route('admin.incidents.create') }}">+ New incident</a>
  </div>

  <div class="filters">
    <a href="{{ route('admin.incidents.index') }}" @class(['on' => ! $filter])>All</a>
    @foreach (\App\Models\Incident::STATUSES as $k => $label)
      <a href="{{ route('admin.incidents.index', ['status' => $k]) }}" @class(['on' => $filter === $k])>{{ $label }}</a>
    @endforeach
  </div>

  <div class="card">
    <table class="t">
      <thead><tr><th>Incident</th><th>Status</th><th>Posted</th><th></th></tr></thead>
      <tbody>
        @forelse ($incidents as $i)
          <tr>
            <td><div class="row-title">{{ $i->title }}@if (! empty($i->media)) <span title="Photos/videos in the Telegram group">📷 {{ count($i->media) }}</span>@endif</div><div class="row-sub">{{ \Illuminate\Support\Str::limit($i->body, 110) }}</div></td>
            <td><span class="badge {{ ['warning' => 'b-danger', 'monitoring' => 'b-warning', 'resolved' => 'b-success'][$i->status] }}">{{ ucfirst($i->status) }}</span></td>
            <td class="row-sub">{{ $i->published_at->format('D j M Y, H:i') }}<br>{{ $i->created_by }} · {{ $i->source }}</td>
            <td>
              <div class="actions">
                @if ($i->status !== 'resolved')
                  <form method="post" action="{{ route('admin.incidents.status', $i) }}">@csrf<input type="hidden" name="status" value="resolved"><button class="btn btn-sm" type="submit">Resolve{{ $groupOn ? ' quietly' : '' }}</button>@if ($groupOn) <button class="btn btn-sm" type="submit" name="announce" value="1" title="Also post to the Telegram group">📣 + alert group</button>@endif</form>
                @endif
                <a class="btn btn-sm" href="{{ route('admin.incidents.edit', $i) }}">Edit</a>
                <form method="post" action="{{ route('admin.incidents.destroy', $i) }}" onsubmit="return confirm('Delete this incident?')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="row-sub">No incidents.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="pager">{{ $incidents->links() }}</div>
@endsection
