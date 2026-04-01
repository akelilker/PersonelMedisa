/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string;
  readonly VITE_API_MODE?: string;
  readonly VITE_DEMO_API_FALLBACK?: string;
  readonly VITE_REALTIME_WS_URL?: string;
  readonly VITE_APP_ENV?: string;
  readonly VITE_APP_VERSION?: string;
  /** vite.config package.json surumu (build zamanı) */
  readonly VITE_PKG_VERSION?: string;
  readonly VITE_ENABLE_DIAGNOSTICS?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare global {
  interface Window {
    appData?: import("./data/app-data.types").AppData;
  }
}

export {};
