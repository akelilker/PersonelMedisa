import { defineConfig } from "vitest/config";
import { heavyMariaDbTestFiles } from "./tests/scripts/heavy-mariadb-test-files.mjs";

export default defineConfig({
  test: {
    include: ["tests/**/*.test.ts", "tests/**/*.test.tsx"],
    exclude: ["**/node_modules/**", "**/dist/**", ...heavyMariaDbTestFiles],
    environment: "node"
  }
});
