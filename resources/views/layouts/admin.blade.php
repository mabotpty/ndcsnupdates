<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $title ?? 'Admin' }} - NDCSN Updates</title>
  @include('layouts._icons')
  <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body class="admin">
  <header class="a-top">
    <div class="wrap">
      <a class="a-brand" href="{{ route('admin.dashboard') }}">NDCSN Updates<small>Admin</small></a>
      <nav class="a-nav">
        <a href="{{ route('admin.dashboard') }}" @class(['on' => request()->routeIs('admin.dashboard')])>Dashboard</a>
        <a href="{{ route('admin.incidents.index') }}" @class(['on' => request()->routeIs('admin.incidents.*')])>Incidents</a>
        <a href="{{ route('admin.weather.index') }}" @class(['on' => request()->routeIs('admin.weather.*')])>Weather</a>
        <a href="{{ route('admin.report') }}" @class(['on' => request()->routeIs('admin.report*')])>Situation Report</a>
        <a href="{{ route('admin.news.index') }}" @class(['on' => request()->routeIs('admin.news.*')])>Latest News</a>
        <a href="{{ route('admin.users') }}" @class(['on' => request()->routeIs('admin.users*')])>Admins</a>
      </nav>
      <div class="a-user">
        <a href="{{ route('home') }}" target="_blank" style="color:#fff">View site ↗</a>
        <a href="{{ route('admin.account') }}" style="color:#fff">{{ auth()->user()->name }}</a>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn-link" type="submit">Log out</button></form>
      </div>
    </div>
  </header>

  <main class="a-main">
    <div class="wrap">
      @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
      @if ($errors->any())
        <div class="errors"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @endif
      @yield('content')
    </div>
  </main>
</body>
</html>
