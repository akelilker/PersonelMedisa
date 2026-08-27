import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const root = process.cwd();

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

function parseTip(content: string, key: string): string | null {
  const match = content.match(new RegExp(`^${key}:\\s*(\\d{3})$`, "im"));
  return match?.[1] ?? null;
}

function latestFilesystemMigrationTip(): string {
  const versions = readdirSync(resolve(root, "api/migrations"))
    .map((file) => {
      const match = file.match(/^(\d+)_/);
      return match ? Number.parseInt(match[1], 10) : 0;
    })
    .filter((n) => n > 0);
  expect(versions.length).toBeGreaterThan(0);
  return String(Math.max(...versions)).padStart(3, "0");
}

describe("canonical state sync ownership", () => {
  it("CI Fast runs npm run check:sync before heavier gates", () => {
    const ci = read(".github/workflows/ci.yml");
    expect(ci).toContain("npm run check:sync");
    const syncIdx = ci.indexOf("npm run check:sync");
    const parityIdx = ci.indexOf("npm run check:api-parity");
    const typecheckIdx = ci.indexOf("npm run typecheck");
    const testsIdx = ci.indexOf("npm run test:ci-fast");
    expect(syncIdx).toBeGreaterThan(-1);
    expect(parityIdx).toBeGreaterThan(syncIdx);
    expect(typecheckIdx).toBeGreaterThan(syncIdx);
    expect(testsIdx).toBeGreaterThan(syncIdx);
  });

  it("CURRENT_STATE + registry machine-readable tips match filesystem tip", () => {
    const fsTip = latestFilesystemMigrationTip();
    const current = read("CURRENT_STATE.md");
    const registry = read("docs/guncel/110-master-closure-gap-registry.md");

    const currentCode = parseTip(current, "CODE_MIGRATION_TIP");
    const currentProd = parseTip(current, "PRODUCTION_MIGRATION_TIP");
    const registryCode = parseTip(registry, "CODE_MIGRATION_TIP");
    const registryProd = parseTip(registry, "PRODUCTION_MIGRATION_TIP");

    expect(currentCode).toBe(fsTip);
    expect(registryCode).toBe(fsTip);
    expect(currentCode).toBe(registryCode);
    expect(currentProd).toBe(registryProd);
    // Production tip may lag code tip until migration apply (076 OPS_ROLLOUT).
    expect(Number.parseInt(currentProd ?? "0", 10)).toBeLessThanOrEqual(
      Number.parseInt(currentCode ?? "0", 10)
    );
  });

  it("verify-canonical-state script parses the three ownership sources", () => {
    const script = read("scripts/verify-canonical-state.mjs");
    expect(script).toContain("api/migrations");
    expect(script).toContain("CURRENT_STATE.md");
    expect(script).toContain("docs/guncel/110-master-closure-gap-registry.md");
    expect(script).toContain("CODE_MIGRATION_TIP");
    expect(script).toContain("PRODUCTION_MIGRATION_TIP");

    const result = spawnSync("npm", ["run", "check:sync"], {
      cwd: root,
      encoding: "utf8",
      shell: true
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[PASS] FILESYSTEM");
    expect(result.stdout).toContain("[PASS] CODE_SYNC");
    expect(result.stdout).toContain("[PASS] PROD_SYNC");
  });
});
