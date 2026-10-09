@extends('layouts.admin', ['title' => 'My account'])

@section('content')
  <div class="a-head"><h1>My account</h1></div>

  <div class="grid2">
    <div class="card panel">
      <h2>Telegram bot</h2>
      @if ($user->telegram_chat_id)
        <p>✅ Your Telegram account is linked. Message the bot <code>/help</code> to see what it can do.</p>
        <form method="post" action="{{ route('admin.account.telegram.unlink') }}" onsubmit="return confirm('Unlink Telegram?')">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Unlink Telegram</button></form>
      @else
        @if ($user->telegram_link_code && $user->telegram_link_expires_at?->isFuture())
          <p>Open the bot in Telegram and send this message within 15 minutes:</p>
          <div class="code">/link {{ $user->telegram_link_code }}</div>
        @else
          <p>Link your Telegram account to post and edit updates from your phone.</p>
        @endif
        <form method="post" action="{{ route('admin.account.telegram') }}">@csrf<button class="btn btn-primary" type="submit">Generate link code</button></form>
      @endif
    </div>

    <div class="card panel">
      <h2>Change password</h2>
      <form method="post" action="{{ route('admin.account.password') }}">
        @csrf @method('PUT')
        <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="field"><label>New password</label><input type="password" name="password" required minlength="10" autocomplete="new-password"><p class="hint">At least 10 characters.</p></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit">Change password</button>
      </form>
    </div>
  </div>
@endsection
