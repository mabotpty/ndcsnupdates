/*
 * NDCSN Updates service worker.
 *
 * Safety information must never be stale on purpose, so pages are NETWORK-FIRST:
 * online you always get the live page; if the network is down (or very slow) you
 * get the last copy you saw, or a friendly offline page. The admin site, the
 * Telegram webhook and anything that isn't a plain GET are never touched.
 */
const VERSION = 'v3';
const STATIC_CACHE = 'ndcsn-static-' + VERSION;
const PAGE_CACHE = 'ndcsn-pages-' + VERSION;
const OFFLINE_URL = '/offline.html';
const MAX_PAGES = 30;
const SLOW_NETWORK_MS = 4000;

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then(function (cache) { return cache.addAll([OFFLINE_URL, '/icons/icon-192.png', '/icons/icon-512.png', '/icons/badge-96.png']); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== STATIC_CACHE && k !== PAGE_CACHE; })
          .map(function (k) { return caches.delete(k); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf('/admin') === 0 || url.pathname.indexOf('/telegram') === 0) return;

  if (req.mode === 'navigate') {
    event.respondWith(networkFirst(req));
  } else if (url.pathname.indexOf('/css/') === 0 || url.pathname.indexOf('/icons/') === 0) {
    event.respondWith(staleWhileRevalidate(req));
  }
});

async function networkFirst(req) {
  var cache = await caches.open(PAGE_CACHE);
  var cached = await cache.match(req);

  var network = fetch(req).then(function (res) {
    // Don't keep redirects or errors (Safari rejects cached redirects for navigations).
    if (res.ok && !res.redirected) {
      cache.put(req, res.clone()).then(function () { return trim(cache); });
    }
    return res;
  });

  if (cached) {
    // Poor signal: after a few seconds fall back to the last copy instead of hanging.
    try {
      return await Promise.race([
        network,
        new Promise(function (_, reject) { setTimeout(reject, SLOW_NETWORK_MS); })
      ]);
    } catch (e) {
      network.catch(function () {}); // let it finish quietly and refresh the cache
      return cached;
    }
  }

  try {
    return await network;
  } catch (e) {
    return (await caches.match(OFFLINE_URL)) || Response.error();
  }
}

async function staleWhileRevalidate(req) {
  var cache = await caches.open(STATIC_CACHE);
  var cached = await cache.match(req);
  var network = fetch(req).then(function (res) {
    if (res.ok) cache.put(req, res.clone());
    return res;
  }).catch(function () { return cached; });
  return cached || network;
}

async function trim(cache) {
  var keys = await cache.keys();
  for (var i = 0; i < keys.length - MAX_PAGES; i++) {
    await cache.delete(keys[i]);
  }
}

/* ---------------------------- push notifications ---------------------------- */

self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }

  event.waitUntil(self.registration.showNotification(data.title || 'NDCSN Updates', {
    body: data.body || '',
    icon: '/icons/icon-192.png',
    badge: '/icons/badge-96.png',
    tag: data.tag || undefined,       // same tag = replaces the earlier notification about the same item
    renotify: !!data.tag,
    lang: 'en-ZA',
    data: { url: data.url || '/' }
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
      for (var i = 0; i < windows.length; i++) {
        if (windows[i].url.indexOf(self.location.origin) === 0 && 'navigate' in windows[i]) {
          return windows[i].navigate(target).then(function (client) { return client && client.focus(); });
        }
      }
      return self.clients.openWindow(target);
    })
  );
});

// If the browser rotates the push subscription, re-subscribe and carry the old choices over.
self.addEventListener('pushsubscriptionchange', function (event) {
  var old = event.oldSubscription;
  if (!old || !old.options) return;

  event.waitUntil(
    self.registration.pushManager.subscribe(old.options).then(function (sub) {
      var body = sub.toJSON();
      body.old_endpoint = old.endpoint;
      return fetch('/push/subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body)
      });
    })
  );
});
