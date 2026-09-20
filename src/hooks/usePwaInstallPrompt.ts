import { useCallback, useEffect, useSyncExternalStore } from "react";
import {
  dismissPwaInstall,
  getPwaInstallState,
  initPwaInstallCapture,
  promptPwaInstall,
  subscribePwaInstall,
  type PwaInstallOutcome,
  type PwaInstallState
} from "../lib/shell/pwa-install";

export type UsePwaInstallPromptResult = PwaInstallState & {
  /** Opens the native install prompt. No-op when no prompt is available. */
  install: () => Promise<PwaInstallOutcome>;
  /** Hides the bar for this page session. */
  dismiss: () => void;
};

/**
 * Reads the early-captured `beforeinstallprompt` store.
 *
 * `canInstall` is false on iOS Safari (the event does not exist there) and in
 * standalone mode, so consumers can render unconditionally.
 */
export function usePwaInstallPrompt(): UsePwaInstallPromptResult {
  const state = useSyncExternalStore(
    subscribePwaInstall,
    getPwaInstallState,
    getPwaInstallState
  );

  useEffect(() => {
    // The bootstrap already owns the listener; this only covers a consumer
    // mounted without `main.tsx` (isolated screens, tests). Idempotent.
    initPwaInstallCapture();
  }, []);

  const install = useCallback(() => promptPwaInstall(), []);
  const dismiss = useCallback(() => dismissPwaInstall(), []);

  return { canInstall: state.canInstall, dismissed: state.dismissed, install, dismiss };
}
