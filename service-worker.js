const CACHE_NAME = "avba-inspector-v2";

// Recursos base para que la app cargue sin conexión.
// Se agregan uno por uno tolerando fallos (si una URL no existe, no rompe el resto).
const urlsToCache = [
  "/",
  "inspector.html",
  "inspeccion.html",
  "manifest.json",
  "icon-192.png",
  "icon-512.png"
];

self.addEventListener("install", event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      Promise.all(urlsToCache.map(u => cache.add(u).catch(() => null)))
    )
  );
});

self.addEventListener("activate", event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", event => {
  const req = event.request;

  // No interferir con envíos (POST a la API): van directo a la red.
  if (req.method !== "GET") return;

  // Navegaciones (abrir la app): red primero, y si no hay, servir la página cacheada.
  if (req.mode === "navigate") {
    event.respondWith(
      fetch(req).catch(() =>
        caches.match("inspector.html").then(r => r || caches.match("/"))
      )
    );
    return;
  }

  // Resto de recursos GET: cache primero, luego red.
  event.respondWith(
    caches.match(req).then(cached => cached || fetch(req))
  );
});
