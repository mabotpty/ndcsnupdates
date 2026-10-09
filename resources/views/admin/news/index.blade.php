@extends('layouts.admin', ['title' => 'SAPS Releases'])

@section('content')
  <div class="a-head">
    <h1>SAPS Releases</h1>
    <a class="btn btn-primary" href="{{ route('admin.news.create') }}">+ Add release</a>
  </div>

  <div class="card">
    <table class="t">
      <thead><tr><th>Title</th><th>Date</th><th></th></tr></thead>
      <tbody>
        @forelse ($items as $n)
          <tr>
            <td><div class="row-title">{{ $n->title }}</div><div class="row-sub">{{ $n->source_name }}</div></td>
            <td class="row-sub">{{ $n->published_at->format('D j M Y, H:i') }}</td>
            <td>
              <div class="actions">
                <a class="btn btn-sm" href="{{ route('admin.news.edit', $n) }}">Edit</a>
                <form method="post" action="{{ route('admin.news.destroy', $n) }}" onsubmit="return confirm('Delete this release?')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="3" class="row-sub">No releases.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="pager">{{ $items->links() }}</div>
@endsection
