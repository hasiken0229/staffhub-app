const CACHE_NAME = "staffhub-static-v2";
const OFFLINE_URLS = ["./manifest.webmanifest"];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS)).then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))),
    ).then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") {
    return;
  }

  const url = new URL(request.url);
  const isApiRequest = url.pathname.includes("/dakoku/api/");
  const isDocumentRequest = request.mode === "navigate" || request.destination === "document";
  if (isApiRequest || isDocumentRequest) {
    event.respondWith(fetch(request));
    return;
  }

  const cacheableDestinations = new Set(["font", "image", "manifest", "script", "style"]);
  if (!cacheableDestinations.has(request.destination)) {
    return;
  }

  event.respondWith(
    fetch(request)
      .then((response) => {
        const cloned = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, cloned));
        return response;
      })
      .catch(async () => {
        const cached = await caches.match(request);
        return cached || caches.match("./");
      }),
  );
});
