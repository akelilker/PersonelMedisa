import { getAppPublicPath } from "../../config/public-base";

/**
 * Service worker registration (Chrome installability owner).
 *
 * Registered exactly once from the app bootstrap (`src/main.tsx`). The worker
 * itself (`public/sw.js`) owns no cache; this module only owns the URL/scope
 * contract. Scope follows the Vite base so production `/personelmedisa/` and
 * root dev both resolve a scope-absolute `sw.js` — a relative path would break
 * on nested SPA routes such as `/personelmedisa/personeller/12`.
 */
const SERVICE_WORKER_FILE = "sw.js";

export function registerServiceWorkerOnce(): void {
  if (typeof window === "undefined" || !("serviceWorker" in navigator)) {
    return;
  }

  const appPublicPath = getAppPublicPath();
  const scope = `${appPublicPath}/`;

  void navigator.serviceWorker
    .register(`${scope}${SERVICE_WORKER_FILE}`, { scope })
    .catch(() => {
      // Registration is an installability requirement, never a boot blocker:
      // Safari/iOS and insecure contexts simply keep working without it.
    });
}
