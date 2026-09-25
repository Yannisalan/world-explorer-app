// Bump the cache name on every deploy that changes a precached file, otherwise
// clients keep the previous copy indefinitely.
const CACHE_NAME = "world-explorer-v13";
const STATIC_ASSETS = [
  "./index.html",
  "./login.html",
  "./register.html",
  "./profile.html",
  "./settings.html",
  "./wishlist.html",
  "./visited.html",
  "./config.js",
  "./app.js",
  "./saved-list.js",
  "./settings.js",
  "./styles.css?v=20260622-streets-pop",
  "./script.js?v=20260622-streets-pop",
  "./data/countries.json",
  "./manifest.webmanifest",
  "./app-icon.svg",
  "./favicon.ico"
];

self.addEventListener("install", event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener("activate", event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener("fetch", event => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);

  // Only the frontend origin is cached. The API is on a different origin
  // entirely, so this also guarantees no session-bearing response is ever
  // stored in the cache: cross-origin requests are left alone before anything
  // else runs.
  if (url.origin !== self.location.origin) return;

  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(() => caches.match("./login.html"))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cachedResponse => {
      if (cachedResponse) return cachedResponse;

      return fetch(request).then(networkResponse => {
        if (networkResponse.ok) {
          const copy = networkResponse.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
        }
        return networkResponse;
      });
    })
  );
});
