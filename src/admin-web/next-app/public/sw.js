const CACHE_VERSION = 5;
const CACHE_NAME = `staffhub-static-v${CACHE_VERSION}`;
const OFFLINE_URLS = ["./manifest.webmanifest"];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS)),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => {
        const previous = /^staffhub-static-v(\d+)$/.exec(key);
        return previous && Number(previous[1]) < CACHE_VERSION;
      }).map((key) => caches.delete(key))),
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
    event.respondWith(fetch(request, { cache: "no-store" }));
    return;
  }

  const cacheableDestinations = new Set(["font", "image", "manifest", "script", "style"]);
  if (!cacheableDestinations.has(request.destination)) {
    return;
  }

  event.respondWith(
    fetch(new Request(request, { cache: request.destination === "script" || request.destination === "style" ? "reload" : "default" }))
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
