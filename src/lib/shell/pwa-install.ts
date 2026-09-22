/**
 * PWA install prompt owner (login "Uygulamayı Yükle" satırı).
 *
 * `beforeinstallprompt` is fired once by the browser and usually lands before
 * React mounts, so the event is captured at app bootstrap (`src/main.tsx`) into
 * this module-level store and read back with `useSyncExternalStore`. Capturing it
 * inside a component effect would silently lose the event.
 *
 * Taşıt parity: the event is deliberately NOT `preventDefault()`-ed, so the
 * browser's own install affordance keeps working alongside our bar.
 */

export type PwaInstallOutcome = "accepted" | "dismissed" | "unavailable";

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice?: Promise<{ outcome: "accepted" | "dismissed"; platform: string }>;
};

export type PwaInstallState = {
  /** Deferred install prompt captured and the app is not already standalone. */
  canInstall: boolean;
  /** Bar closed by the user, or the native prompt already consumed. */
  dismissed: boolean;
};

let deferredPrompt: BeforeInstallPromptEvent | null = null;
let dismissed = false;
let initialized = false;
const listeners = new Set<() => void>();

let state: PwaInstallState = { canInstall: false, dismissed: false };

function isStandaloneMode(): boolean {
  if (typeof window === "undefined") {
    return false;
  }
  const navigatorStandalone =
    (navigator as Navigator & { standalone?: boolean }).standalone === true;
  return window.matchMedia("(display-mode: standalone)").matches || navigatorStandalone;
}

/** Publishes a new snapshot only when a field really changed (stable reference). */
function publish(): void {
  const canInstall = deferredPrompt !== null && !isStandaloneMode();
  if (canInstall === state.canInstall && dismissed === state.dismissed) {
    return;
  }
  state = { canInstall, dismissed };
  listeners.forEach((listener) => listener());
}

function releasePrompt(): void {
  // The deferred event is single-use: the browser never re-fires it for the same
  // install opportunity, so it is dropped once the native prompt was shown.
  deferredPrompt = null;
  dismissed = true;
  publish();
}

/** Idempotent. Called by the bootstrap and defensive on hook mount. */
export function initPwaInstallCapture(): void {
  if (initialized || typeof window === "undefined") {
    return;
  }
  initialized = true;

  window.addEventListener("beforeinstallprompt", (event) => {
    deferredPrompt = event as BeforeInstallPromptEvent;
    dismissed = false;
    publish();
  });

  window.addEventListener("appinstalled", () => {
    deferredPrompt = null;
    dismissed = true;
    publish();
  });
}

export function subscribePwaInstall(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getPwaInstallState(): PwaInstallState {
  return state;
}

export async function promptPwaInstall(): Promise<PwaInstallOutcome> {
  const promptEvent = deferredPrompt;
  if (!promptEvent) {
    return "unavailable";
  }

  try {
    await promptEvent.prompt();
    const choice = await promptEvent.userChoice;
    releasePrompt();
    return choice?.outcome === "accepted" ? "accepted" : "dismissed";
  } catch {
    // A rejected prompt is a closed install opportunity, not a broken screen.
    releasePrompt();
    return "dismissed";
  }
}

export function dismissPwaInstall(): void {
  if (dismissed) {
    return;
  }
  dismissed = true;
  publish();
}
