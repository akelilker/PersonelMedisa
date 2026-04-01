import pkg from "./package.json";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  base: "./",
  plugins: [react()],
  define: {
    "import.meta.env.VITE_PKG_VERSION": JSON.stringify(pkg.version)
  }
});
