import { defineConfig } from "vitest/config";
import { fastCiCriticalTestFiles } from "./tests/scripts/fast-ci-critical-test-files.mjs";

export default defineConfig({
  test: {
    include: fastCiCriticalTestFiles,
    environment: "node"
  }
});
