/** Merkezi ortam okuma — sabit URL/mode kod icine gomulmez. */

export type AppEnvName = "development" | "production" | "test" | string;

export function getAppEnv(): AppEnvName {
  const v = import.meta.env.VITE_APP_ENV?.trim();
  if (v) {
    return v;
  }
  return import.meta.env.MODE;
}

export function getAppVersion(): string {
  const fromEnv = import.meta.env.VITE_APP_VERSION?.trim();
  if (fromEnv && fromEnv.length > 0) {
    return fromEnv;
  }
  const fromPkg = import.meta.env.VITE_PKG_VERSION?.trim();
  if (fromPkg && fromPkg.length > 0) {
    return fromPkg;
  }
  return "0.1.0";
}

export function isDevRuntime(): boolean {
  return import.meta.env.DEV === true;
}
