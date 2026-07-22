import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    include: ["tests/**/*.test.ts", "tests/**/*.test.tsx"],
    environment: "node",
    globalTeardown: "./tests/scripts/vitest-mariadb-teardown.mjs"
  }
});
