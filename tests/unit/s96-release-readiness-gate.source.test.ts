import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function runGate(env: NodeJS.ProcessEnv = {}) {
  return spawnSync(process.execPath, [resolve(process.cwd(), "scripts/release-readiness-gate.mjs")], {
    cwd: process.cwd(),
    env: { ...process.env, ...env },
    encoding: "utf8"
  });
}

describe("S96 release readiness gate", () => {
  it("CODE_READY_OPS_PENDING without ops acknowledgements", () => {
    const result = runGate({
      REQUIRE_OPS_READY: "",
      SMOKE_BASE_URL: "",
      RELEASE_GATE_SGK_OFFICIAL_SOURCE: "",
      RELEASE_GATE_UBGT_SEED_APPROVED: "",
      RELEASE_GATE_PROD_WRITE_APPROVED: "",
      RELEASE_GATE_AUTH_SMOKE_CREDENTIAL: ""
    });
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("CODE_READY_OPS_PENDING");
    expect(result.stdout).toContain("[WAITING] SGK katalog resmi kaynak paketi");
    expect(result.stdout).toContain("[OK] src native dialog = 0");
    expect(result.stdout).toContain("[OK] SGK approve_aktif_mi=false");
  });

  it("REQUIRE_OPS_READY=1 exits 2 while ops pending", () => {
    const result = runGate({
      REQUIRE_OPS_READY: "1",
      SMOKE_BASE_URL: "",
      RELEASE_GATE_SGK_OFFICIAL_SOURCE: "",
      RELEASE_GATE_UBGT_SEED_APPROVED: "",
      RELEASE_GATE_PROD_WRITE_APPROVED: "",
      RELEASE_GATE_AUTH_SMOKE_CREDENTIAL: ""
    });
    expect(result.status).toBe(2);
    expect(result.stderr + result.stdout).toContain("OPS_PENDING");
  });

  it("FULL_READY when all ops gates are acknowledged", () => {
    const result = runGate({
      REQUIRE_OPS_READY: "1",
      SMOKE_BASE_URL: "",
      RELEASE_GATE_SGK_OFFICIAL_SOURCE: "ready",
      RELEASE_GATE_UBGT_SEED_APPROVED: "ready",
      RELEASE_GATE_PROD_WRITE_APPROVED: "ready",
      RELEASE_GATE_AUTH_SMOKE_CREDENTIAL: "ready"
    });
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("FULL_READY");
  });

  it("post-deploy smoke supports optional auth env documentation", () => {
    const smoke = readFileSync(resolve(process.cwd(), "scripts/post-deploy-smoke.mjs"), "utf8");
    expect(smoke).toContain("SMOKE_AUTH_USERNAME");
    expect(smoke).toContain("SMOKE_AUTH_PASSWORD");
    expect(smoke).toContain("checkAuthenticatedReadOnly");
    expect(smoke).toContain("/api/auth/smoke-read");
    expect(smoke).toContain("no domain writes; no PII read");
  });

  it("ops runbook documents four external gates", () => {
    const runbook = readFileSync(
      resolve(process.cwd(), "docs/guncel/95-s96-release-ops-runbook.md"),
      "utf8"
    );
    expect(runbook).toContain("RELEASE_GATE_SGK_OFFICIAL_SOURCE");
    expect(runbook).toContain("RELEASE_GATE_UBGT_SEED_APPROVED");
    expect(runbook).toContain("RELEASE_GATE_PROD_WRITE_APPROVED");
    expect(runbook).toContain("RELEASE_GATE_AUTH_SMOKE_CREDENTIAL");
    expect(runbook).toContain("Production write bu koşuda yapılmadı");
  });
});
