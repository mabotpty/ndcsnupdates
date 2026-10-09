@extends('layouts.admin', ['title' => 'Admins'])

@section('content')
  <div class="a-head"><h1>Admins</h1></div>

  <div class="grid2">
    <div class="card">
      <table class="t">
        <thead><tr><th>Admin</th><th>Telegram</th><th></th></tr></thead>
        <tbody>
          @foreach ($users as $u)
            <tr>
              <td><div class="row-title">{{ $u->name }}</div><div class="row-sub">{{ $u->email }}</div></td>
              <td>{{ $u->telegram_chat_id ? '✅ Linked' : '—' }}</td>
              <td>
                @unless ($u->is(auth()->user()))
                  <form method="post" action="{{ route('admin.users.destroy', $u) }}" onsubmit="return confirm('Remove this admin?')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" type="submit">Remove</button></form>
                @endunless
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <form class="card panel" method="post" action="{{ route('admin.users.store') }}">
      @csrf
      <h2>Add an admin</h2>
      <div class="field"><label>Name</label><input type="text" name="name" required></div>
      <div class="field"><label>Email</label><input type="email" name="email" required></div>
      <div class="field"><label>Temporary password</label><input type="password" name="password" required minlength="10" autocomplete="new-password"><p class="hint">At least 10 characters. They can change it under My account.</p></div>
      <button class="btn btn-primary" type="submit">Add admin</button>
    </form>
  </div>
@endsection
