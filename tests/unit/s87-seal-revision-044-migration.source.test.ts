import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const MIGRATION = "api/migrations/044_puantaj_aylik_muhur_revision_reopen.sql";

describe("S87 migration 044 seal revision reopen source contracts", () => {
  it("exists and is tip of chain", () => {
    expect(existsSync(resolve(MIGRATION))).toBe(true);
    const files = readdirSync(resolve("api/migrations"))
      .filter((n) => /^\d{3}_.+\.sql$/.test(n))
      .sort();
    expect(files.at(-1)).toBe("045_sirket_politikasi_kanit_owner.sql");
  });

  it("is additive revision + dual-control reopen without destructive rewrite", () => {
    const sql = readFileSync(resolve(MIGRATION), "utf8");
    expect(sql).toMatch(/revision_no/);
    expect(sql).toMatch(/parent_muhur_id/);
    expect(sql).toMatch(/superseded_by_id/);
    expect(sql).toMatch(/source_hash/);
    expect(sql).toMatch(/aktif_muhur/);
    expect(sql).toMatch(/uq_pam_sube_donem_revision/);
    expect(sql).toMatch(/uq_pam_aktif_muhur/);
    expect(sql).toMatch(/puantaj_donem_reopen_talepleri/);
    expect(sql).toMatch(/puantaj_donem_reopen_auditleri/);
    expect(sql).toMatch(/ONAY_BEKLIYOR/);
    expect(sql).toMatch(/acik_talep_slot/);
    expect(sql).not.toMatch(/DROP TABLE/i);
    expect(sql).not.toMatch(/\bDELETE FROM\b/i);
    expect(sql).not.toMatch(/INSERT INTO\s+kullanicilar/i);
  });

  it("wires reopen permissions and API owners", () => {
    const rolesPhp = readFileSync(resolve("api/src/Auth/RolePermissions.php"), "utf8");
    const rolesTs = readFileSync(resolve("src/lib/authorization/role-permissions.ts"), "utf8");
    const router = readFileSync(resolve("api/src/Router.php"), "utf8");
    const service = readFileSync(resolve("api/src/Services/PuantajDonemReopenService.php"), "utf8");
    for (const code of [
      "puantaj.donem_reopen.request",
      "puantaj.donem_reopen.approve",
      "puantaj.donem_reseal",
      "puantaj.donem_seal.history",
    ]) {
      expect(rolesPhp).toContain(code);
      expect(rolesTs).toContain(code);
    }
    expect(router).toMatch(/reopen-request/);
    expect(router).toMatch(/reopen-approve/);
    expect(router).toMatch(/reopen-reject/);
    expect(router).toMatch(/reseal/);
    expect(router).toMatch(/seal-history/);
    expect(service).toContain("REOPEN_SELF_APPROVAL_FORBIDDEN");
    expect(service).toContain("ACTIVE_SNAPSHOT_MUST_BE_CANCELLED");
    expect(service).toContain("PERIOD_RESEALED");
  });
});
