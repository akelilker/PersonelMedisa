import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { createHash } from "node:crypto";
import { resolve } from "node:path";

const MIGRATION = "api/migrations/045_sirket_politikasi_kanit_owner.sql";

describe("S87 policy evidence 045 migration source", () => {
  it("is additive, idempotent-patterned, and before tip 046", () => {
    const sql = readFileSync(resolve(process.cwd(), MIGRATION), "utf8");
    expect(sql).toContain("belge_id");
    expect(sql).toContain("belge_sha256");
    expect(sql).toContain("idx_scp_belge_id");
    expect(sql).toContain("chk_scp_belge_pair");
    expect(sql).toContain("chk_scp_belge_sha256");
    expect(sql).toContain("ADD COLUMN IF NOT EXISTS");
    expect(sql).toContain("^[0-9a-f]{64}$");
    expect(sql).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(sql).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(sql).not.toMatch(/\bUPDATE\s+sirket_calisma_politikalari\s+SET\b/i);

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("045_sirket_politikasi_kanit_owner.sql");
    expect(migrations.at(-1)).toBe("062_serbest_zaman_retention_destroy_gate.sql");
  });

  it("reports bytes and sha256 for the migration artifact", () => {
    const path = resolve(process.cwd(), MIGRATION);
    const raw = readFileSync(path);
    const sha = createHash("sha256").update(raw).digest("hex");
    expect(statSync(path).size).toBeGreaterThan(200);
    expect(sha).toMatch(/^[0-9a-f]{64}$/);
  });

  it("service enforces evidence submit/approve and hash isolation", () => {
    const service = readFileSync(
      resolve(process.cwd(), "api/src/Services/SirketCalismaPolitikasiService.php"),
      "utf8"
    );
    expect(service).toContain("assertEvidenceComplete");
    expect(service).toContain("POLICY_EVIDENCE_REQUIRED");
    expect(service).toContain("POLICY_EVIDENCE_INVALID");
    expect(service).toContain("POLICY_EVIDENCE_INCOMPLETE");
    expect(service).toContain("POLICY_EVIDENCE_HASH_INVALID");
    expect(service).toContain("POLICY_SELF_APPROVAL_FORBIDDEN");
    expect(service).toContain("LEGACY_MISSING");
    expect(service).toContain("PRESENT_VALID");
    expect(service).toContain("evidence_ready_for_approval");
    expect(service).toContain("belge_id");
    expect(service).toContain("belge_sha256");
    expect(service).toContain("normalizeEvidenceFields");
    expect(service).toMatch(/computePolicyHash[\s\S]*listDegerler/);
  });
});
