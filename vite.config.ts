import pkg from "./package.json";
import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";

function normalizeViteBase(raw: string | undefined): string {
  const t = raw?.trim();
  if (!t || t === "./") {
    return "./";
  }
  if (t === "/") {
    return "/";
  }
  let path = t;
  if (!path.startsWith("/") && !path.startsWith("./")) {
    path = `/${path}`;
  }
  return path.endsWith("/") ? path : `${path}/`;
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");
  const base = normalizeViteBase(env.VITE_APP_BASE_PATH);

  return {
    base,
    plugins: [react()],
    define: {
      "import.meta.env.VITE_PKG_VERSION": JSON.stringify(pkg.version)
    }
  };
});
