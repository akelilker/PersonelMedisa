/** Merkezi ortam okuma — sabit URL/mode kod icine gomulmez. */

export type AppEnvName = "development" | "production" | "test" | string;
export type AppApiMode = "auto" | "real" | "demo";

const ENV_META = (import.meta as ImportMeta & { env?: Record<string, string | boolean | undefined> }).env;

export function getAppEnv(): AppEnvName {
  const v = ENV_META?.VITE_APP_ENV?.trim();
  if (v) {
    return v;
  }
  return ENV_META?.MODE ?? "production";
}

export function getAppVersion(): string {
  const fromEnv = ENV_META?.VITE_APP_VERSION?.trim();
  if (fromEnv && fromEnv.length > 0) {
    return fromEnv;
  }
  const fromPkg = ENV_META?.VITE_PKG_VERSION?.trim();
  if (fromPkg && fromPkg.length > 0) {
    return fromPkg;
  }
  return "0.1.0";
}

export function isDevRuntime(): boolean {
  return ENV_META?.DEV === true;
}

export function isProductionBuild(): boolean {
  return ENV_META?.PROD === true || getAppEnv() === "production";
}

export function getApiMode(): AppApiMode {
  const normalized = (ENV_META?.VITE_API_MODE ?? "").trim().toLowerCase();
  if (normalized === "real") {
    return "real";
  }

  if (normalized === "demo" || normalized === "mock") {
    return "demo";
  }

  return "auto";
}

export function isDemoApiFallbackEnabled(): boolean {
  if (isProductionBuild()) {
    const fromEnv = String(ENV_META?.VITE_DEMO_API_FALLBACK ?? "false").toLowerCase();
    return fromEnv === "true" || fromEnv === "1";
  }
  const fromEnv = String(ENV_META?.VITE_DEMO_API_FALLBACK ?? "true").toLowerCase();
  return fromEnv !== "false" && fromEnv !== "0";
}

/**
 * Demo session/mock API gibi gercek olmayan davranislarin KULLANILMAMASI gereken durum.
 * Production build'de daima true'dur. Guvenlik kararlarinda bu kullanilir.
 */
export function isRealBackendOnlyMode(): boolean {
  if (isProductionBuild()) {
    return true;
  }
  return getApiMode() === "real" || !isDemoApiFallbackEnabled();
}
/** Medisa product UI version shown in the app footer (not npm/package version). */
export const MEDISA_UI_VERSION = "v78.2";

/**
 * Taşit canonical footer device suffix:
 * - standalone + iOS => " iOS PWA"
 * - standalone => " PWA"
 * - mobile browser => " Mobil"
 * - desktop browser => ""
 */
export function getFooterDeviceSuffix(): string {
  if (typeof window === "undefined" || typeof navigator === "undefined") {
    return "";
  }
  const ua = navigator.userAgent || (navigator as Navigator & { vendor?: string }).vendor || "";
  const isIOS = /iPad|iPhone|iPod/.test(ua) && !(window as Window & { MSStream?: unknown }).MSStream;
  const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);
  const isPWA =
    window.matchMedia("(display-mode: standalone)").matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true;
  if (isPWA) {
    return isIOS ? " iOS PWA" : " PWA";
  }
  if (isMobile) {
    return " Mobil";
  }
  return "";
}

export function getFooterVersionLabel(): string {
  return `${MEDISA_UI_VERSION}${getFooterDeviceSuffix()}`;
}
