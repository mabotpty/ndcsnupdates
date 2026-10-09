<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Log in - NDCSN Updates</title>
  <link rel="stylesheet" href="{{ asset('css/site.css') }}">
  <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body class="admin">
  <div class="login-wrap">
    <form class="card login-card" method="post" action="{{ route('admin.login') }}">
      @csrf
      <p class="section-label">NDCSN Updates</p>
      <h1>Admin log in</h1>
      <p class="hint" style="margin-bottom:20px">Safety status backend</p>
      @if ($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
      <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus></div>
      <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required></div>
      <div class="field"><label style="font-weight:500"><input type="checkbox" name="remember" value="1"> Keep me signed in</label></div>
      <button class="btn btn-primary" style="width:100%;justify-content:center" type="submit">Log in</button>
    </form>
  </div>
</body>
</html>
