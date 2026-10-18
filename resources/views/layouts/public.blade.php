<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#14407a">
  <title>{{ $title ?? 'North Durban and Surrounds Safety Status Updates' }} - NDCSN</title>
  <meta name="description" content="Live safety status updates for North Durban and surrounds, from the North Durban Community Support Network.">
  @include('layouts._icons')

  {{-- Installable app (PWA) --}}
  {{-- Some Android browsers (Samsung Internet etc.) package installs with their own, outdated Android wrapper, which
       Google Play Protect flags as an "unsafe app". Only offer installation to browsers that don't do that: those
       get no manifest, so they can't build the wrapper. They are pointed to Chrome instead (see the install bar). --}}
  @php($ua = (string) request()->userAgent())
  @php($androidOtherBrowser = preg_match('/Android/i', $ua) && preg_match('/SamsungBrowser|MiuiBrowser|UCBrowser|HeyTapBrowser|VivoBrowser|HuaweiBrowser|Puffin|Silk\//i', $ua))
  @unless ($androidOtherBrowser)
    <link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
  @endunless
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="NDCSN Updates">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

  @if (config('services.webpush.public'))
    <meta name="vapid-public-key" content="{{ config('services.webpush.public') }}">
  @endif

  <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
  <noscript><meta http-equiv="refresh" content="60"></noscript>
</head>
<body>
  <header class="site-header">
    <div class="wrap">
      <div class="brand">
        <a class="brand-logo" href="{{ route('home') }}" aria-label="NDCSN Community Updates – home">
          <img src="{{ url('/icons/icon-192.png') }}?v=2" alt="NDCSN Community Updates logo" width="88" height="88">
        </a>
        <div class="brand-text">
          <h1>North Durban Community Support Network</h1>
          <h2>{{ $subtitle ?? 'North Durban and Surrounds Safety Status Updates' }}</h2>
        </div>
      </div>
      <div class="header-meta">
        <span><span class="live-dot"></span><span id="updated">Last updated: {{ $updatedAt?->format('l j F Y, H:i') ?? '—' }}</span></span>
        <span id="offline-badge" class="offline-badge" hidden>Offline – showing the last update</span>
        <span class="refresh-note">This page refreshes automatically every minute.</span>
        <button type="button" id="notify-btn" class="btn-install" hidden>🔔 Notifications</button>
        <nav class="nav" aria-label="Pages">
          @php($activeWeather = \App\Models\Incident::weather()->where('status', '!=', 'resolved')->count())
          <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Safety Status</a>
          <a href="{{ route('weather.index') }}" @if(request()->routeIs('weather.index', 'weather')) aria-current="page" @endif>Weather Alerts @if ($activeWeather)<span class="nav-count">{{ $activeWeather }}</span>@endif</a>
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

  @if (config('services.webpush.public'))
  <dialog id="notify-dialog" class="notify" aria-labelledby="notify-title">
    <form method="dialog" class="notify-form" id="notify-form">
      <h2 id="notify-title">🔔 Notifications</h2>
      <p class="notify-msg" id="notify-msg"></p>

      <fieldset id="notify-options">
        <legend>Tell me about…</legend>
        @foreach (\App\Services\PushNotifier::CATEGORIES as $key => $label)
          <label class="notify-opt"><input type="checkbox" name="categories" value="{{ $key }}" @checked($key !== 'resolved')> <span>{{ $label }}</span></label>
        @endforeach
        <label class="notify-level" for="notify-min">Weather warnings from level
          <select id="notify-min">
            <option value="0">1 (all warnings)</option>
            <option value="3">3 and above</option>
            <option value="5">5 and above (orange/red)</option>
            <option value="8">8 and above (severe)</option>
          </select>
        </label>
      </fieldset>

      <div class="notify-actions">
        <button type="button" class="btn-notify primary" id="notify-save">Turn on notifications</button>
        <button type="button" class="btn-notify danger" id="notify-off" hidden>Turn off</button>
        <button type="submit" class="btn-notify">Close</button>
      </div>
    </form>
  </dialog>
  @endif

  <script>
    (function () {
      var mobile = window.matchMedia('(max-width: 760px)');

      // Collapsible sections (only active on phones; always open on larger screens).
      // The choice is remembered for the visit, including across the live refresh below.
      function store(key, val) { try { val === undefined ? sessionStorage.removeItem(key) : sessionStorage.setItem(key, val); } catch (e) {} }
      function load(key) { try { return sessionStorage.getItem(key); } catch (e) { return null; } }

      function applySections() {
        document.querySelectorAll('.sec').forEach(function (s) {
          var saved = load('sec:' + s.dataset.key);
          var collapsed = saved === null ? s.dataset.default === 'collapsed' : saved === '1';
          s.classList.toggle('collapsed', collapsed);
          var t = s.querySelector('.toggle');
          if (t) t.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
      }

      document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('.toggle') : null;
        if (!t || !mobile.matches) return;
        var s = t.closest('.sec');
        if (!s) return;
        var collapsed = !s.classList.contains('collapsed');
        s.classList.toggle('collapsed', collapsed);
        t.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        store('sec:' + s.dataset.key, collapsed ? '1' : '0');
      });

      applySections();

      // Refresh the live content in place (no full page flash): every minute, and straight away
      // when the app comes back to the foreground or the connection returns.
      var busy = false;
      var offline = document.getElementById('offline-badge');
      function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(location.href, { cache: 'no-store', headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
          .then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var live = doc.getElementById('live'), stamp = doc.getElementById('updated');
            if (live) { document.getElementById('live').innerHTML = live.innerHTML; applySections(); if (window.applyInstall) window.applyInstall(); }
            if (stamp) document.getElementById('updated').textContent = stamp.textContent;
            offline.hidden = true;
          })
          .catch(function () { offline.hidden = false; })
          .then(function () { busy = false; });
      }
      setInterval(refresh, 60000);
      document.addEventListener('visibilitychange', refresh);
      window.addEventListener('online', refresh);
      window.addEventListener('offline', function () { offline.hidden = false; });

      // Installable app: offline support + "Install app" button.
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
      }

      // "Install it as an app" bar (home page). It lives inside the live-refreshed area, so clicks are
      // delegated and the how-to text is re-applied after every refresh.
      var deferred = null;
      var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
      var ua = navigator.userAgent;
      var os = /iphone|ipad|ipod/i.test(ua) && !window.MSStream ? 'ios'
        : (/android/i.test(ua) ? (/SamsungBrowser|MiuiBrowser|UCBrowser|HeyTapBrowser|VivoBrowser|HuaweiBrowser|Puffin|Silk\//i.test(ua) ? 'android-other' : 'android') : 'desktop');
      if (standalone) document.documentElement.classList.add('is-installed');

      function applyInstall() {
        document.querySelectorAll('[data-os]').forEach(function (el) { el.hidden = el.dataset.os !== os; });
      }
      window.applyInstall = applyInstall;
      applyInstall();

      window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; });
      window.addEventListener('appinstalled', function () { deferred = null; document.documentElement.classList.add('is-installed'); });

      document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-install]') : null;
        if (!btn) return;
        if (deferred) {
          // Android/desktop Chrome & Edge: the browser's own install prompt.
          deferred.prompt();
          deferred.userChoice.then(function () { deferred = null; });
        } else {
          // iOS Safari (and others) can't be prompted: show the steps instead.
          var help = document.getElementById('install-help');
          if (help) help.hidden = !help.hidden;
        }
      });

      // Push notifications: each device picks what it wants to hear about.
      var vapidMeta = document.querySelector('meta[name="vapid-public-key"]');
      if (vapidMeta) {
        var notifyBtn = document.getElementById('notify-btn');
        var dialog = document.getElementById('notify-dialog');
        var msg = document.getElementById('notify-msg');
        var options = document.getElementById('notify-options');
        var saveBtn = document.getElementById('notify-save');
        var offBtn = document.getElementById('notify-off');
        var minLevel = document.getElementById('notify-min');
        var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('#notify-form input[name=categories]')); };
        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
        var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        var current = null; // this device's PushSubscription, if any

        notifyBtn.hidden = false;

        function b64ToUint8(b64) {
          var pad = '='.repeat((4 - b64.length % 4) % 4);
          var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
          var out = new Uint8Array(raw.length);
          for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
          return out;
        }
        function post(url, body) {
          return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); });
        }
        function prefs() {
          return {
            categories: boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; }),
            min_weather_level: parseInt(minLevel.value, 10) || 0
          };
        }
        function setBusy(on) { saveBtn.disabled = on; offBtn.disabled = on; }
        function paint() {
          var on = !!current;
          notifyBtn.textContent = on ? '🔔 Notifications on' : '🔔 Notifications';
          saveBtn.textContent = on ? 'Save choices' : 'Turn on notifications';
          offBtn.hidden = !on;
        }

        function describeBlocked(text) {
          msg.textContent = text;
          options.hidden = true; saveBtn.hidden = true; offBtn.hidden = true;
        }

        function load() {
          options.hidden = false; saveBtn.hidden = false;
          msg.textContent = 'Get an alert on this device when something is posted – even when the app is closed. Choose what you want:';
          if (!pushSupported) {
            describeBlocked(isIos && !standalone
              ? 'On iPhone/iPad, first install this app: tap Share in Safari, then “Add to Home Screen”. Open it from your home screen and come back here to turn notifications on.'
              : 'This browser doesn’t support push notifications.');
            return Promise.resolve();
          }
          if (Notification.permission === 'denied') {
            describeBlocked('Notifications are blocked for this site. Allow them in your browser or phone settings for this site, then come back here.');
            return Promise.resolve();
          }
          return navigator.serviceWorker.ready
            .then(function (reg) { return reg.pushManager.getSubscription(); })
            .then(function (sub) {
              current = sub; paint();
              if (!sub) return;
              return post('/push/status', { endpoint: sub.endpoint }).then(function (s) {
                if (!s.subscribed) { return; }
                boxes().forEach(function (b) { b.checked = s.categories.indexOf(b.value) !== -1; });
                minLevel.value = String([0, 3, 5, 8].indexOf(s.min_weather_level) !== -1 ? s.min_weather_level : 0);
              });
            })
            .catch(function () {});
        }

        saveBtn.addEventListener('click', function () {
          var p = prefs();
          if (!p.categories.length) { msg.textContent = 'Pick at least one thing to be notified about (or use Turn off).'; return; }
          setBusy(true);
          var ready = navigator.serviceWorker.ready;
          var subscribe = current ? Promise.resolve(current) : Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') return Promise.reject('denied');
            return ready.then(function (reg) {
              return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(vapidMeta.content) });
            });
          });
          subscribe
            .then(function (sub) {
              current = sub;
              var body = sub.toJSON(); body.categories = p.categories; body.min_weather_level = p.min_weather_level;
              return post('/push/subscribe', body);
            })
            .then(function () { paint(); msg.textContent = '✅ Saved. You’ll get alerts on this device.'; })
            .catch(function (e) {
              msg.textContent = e === 'denied' ? 'Permission wasn’t given, so notifications are off.' : 'Sorry, that didn’t work. Please try again.';
            })
            .then(function () { setBusy(false); });
        });

        offBtn.addEventListener('click', function () {
          if (!current) return;
          setBusy(true);
          var sub = current;
          post('/push/unsubscribe', { endpoint: sub.endpoint })
            .then(function () { return sub.unsubscribe(); })
            .then(function () { current = null; paint(); msg.textContent = 'Notifications are off for this device.'; })
            .catch(function () { msg.textContent = 'Sorry, that didn’t work. Please try again.'; })
            .then(function () { setBusy(false); });
        });

        notifyBtn.addEventListener('click', function () {
          load().then(function () { if (dialog.showModal) { dialog.showModal(); } else { dialog.setAttribute('open', ''); } });
        });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); }); // click outside to close

        // Reflect the current state on the button without opening the dialog.
        if (pushSupported) {
          navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); })
            .then(function (sub) { current = sub; paint(); }).catch(function () {});
        }
      }
    })();
  </script>
</body>
</html>
