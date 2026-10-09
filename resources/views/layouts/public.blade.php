<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#14407a">
  <title>{{ $title ?? 'North Durban and Surrounds Safety Status Updates' }} - NDCSN</title>
  <meta name="description" content="Live safety status updates for North Durban and surrounds, from the North Durban Community Support Network.">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2314407a'/%3E%3Cpath d='M16 6l9 3.5v6.2c0 5.2-3.6 9.1-9 10.8-5.4-1.7-9-5.6-9-10.8V9.5z' fill='%23fff'/%3E%3C/svg%3E">
  <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
  <noscript><meta http-equiv="refresh" content="60"></noscript>
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <p class="eyebrow">NDCSN</p>
      <h1>North Durban Community Support Network</h1>
      <h2>{{ $subtitle ?? 'North Durban and Surrounds Safety Status Updates' }}</h2>
      <div class="header-meta">
        <span><span class="live-dot"></span><span id="updated">Last updated: {{ $updatedAt?->format('l j F Y, H:i') ?? '—' }}</span></span>
        <span>This page updates automatically every minute.</span>
        <nav class="nav" aria-label="Pages">
          <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Safety Status</a>
          <a href="{{ route('news') }}" @if(request()->routeIs('news')) aria-current="page" @endif>SAPS Releases</a>
        </nav>
      </div>
    </div>
  </header>

  <main>
    <div class="wrap" id="live">
      @yield('content')
    </div>
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <span>&copy; {{ date('Y') }} North Durban Community Support Network</span>
      <span>Emergency? Contact SAPS, Metro Police or your security provider directly.</span>
    </div>
  </footer>

  <script>
    // Refresh the live content in place every minute (no full page flash).
    (function () {
      var busy = false;
      setInterval(function () {
        if (busy || document.hidden) return;
        busy = true;
        fetch(location.href, { cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var live = doc.getElementById('live'), stamp = doc.getElementById('updated');
            if (live) document.getElementById('live').innerHTML = live.innerHTML;
            if (stamp) document.getElementById('updated').textContent = stamp.textContent;
          })
          .catch(function () {})
          .then(function () { busy = false; });
      }, 60000);
    })();
  </script>
</body>
</html>
