/*
 * PersonelMedisa service worker — PWA installability owner.
 *
 * Chrome only fires `beforeinstallprompt` when the app has an active service
 * worker with a `fetch` handler next to an installable manifest. This worker is
 * deliberately cache-free: it owns no asset cache and synthesizes no response,
 * so it can never serve a stale JS/CSS/API payload. The install prompt UI lives
 * in `src/components/shell/PwaInstallBar.tsx`; the registration contract lives in
 * `src/lib/shell/register-service-worker.ts`.
 *
 * Offline caching can be added later inside the same `fetch` handler without
 * changing the registration or the scope.
 */

self.addEventListener("install", () => {
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});

// Pass-through. The listener is never responded to, so the browser performs its
// default network fetch for every request.
self.addEventListener("fetch", () => {});
