const CACHE_NAME = "avba-app-v2";

const urlsToCache = [
"/login.html",
"/manifest.json",
"/icon-192.png",
"/icon-512.png"
];

// Instalar: precachear de forma tolerante (un archivo faltante no rompe la instalación)
self.addEventListener("install", event => {
self.skipWaiting();
event.waitUntil(
caches.open(CACHE_NAME).then(cache =>
Promise.all(urlsToCache.map(url =>
cache.add(url).catch(() => null)
))
)
);
});

// Activar: borrar cachés viejos (p. ej. avba-inspector-v1)
self.addEventListener("activate", event => {
event.waitUntil(
caches.keys().then(keys =>
Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
).then(() => self.clients.claim())
);
});

self.addEventListener("fetch", event => {
const req = event.request;

// Solo manejar GET; nunca interferir con la API ni con peticiones POST
if (req.method !== "GET" || req.url.includes("/api/")) return;

// Navegaciones (HTML): red primero, caché como respaldo offline
if (req.mode === "navigate") {
event.respondWith(
fetch(req).catch(() => caches.match(req).then(r => r || caches.match("/login.html")))
);
return;
}

// Resto de recursos GET: caché primero, luego red
event.respondWith(
caches.match(req).then(r => r || fetch(req))
);
});
