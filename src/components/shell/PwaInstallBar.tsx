import { usePwaInstallPrompt } from "../../hooks/usePwaInstallPrompt";

/**
 * Login "Uygulamayı Yükle" satırı — Taşıt driver-shell login parity.
 *
 * Only renders when the browser handed us a deferred install prompt and the app
 * is not already running standalone. iOS/Safari never fires
 * `beforeinstallprompt`, so nothing renders there (no share-sheet guidance this
 * round).
 */
export function PwaInstallBar() {
  const { canInstall, dismissed, install, dismiss } = usePwaInstallPrompt();

  if (!canInstall || dismissed) {
    return null;
  }

  return (
    <div className="pwa-install-bar" data-testid="pwa-install-bar">
      <button
        type="button"
        className="pwa-install-btn"
        data-testid="pwa-install-btn"
        onClick={() => {
          void install();
        }}
      >
        Uygulamayı Yükle
      </button>
      <button
        type="button"
        className="pwa-install-close"
        data-testid="pwa-install-close"
        aria-label="İptal"
        onClick={dismiss}
      >
        <svg
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          focusable="false"
        >
          <line x1="18" y1="6" x2="6" y2="18" />
          <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
      </button>
    </div>
  );
}
