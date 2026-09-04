import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

const migration = read("api/migrations/085_gunluk_bildirim_duzeltme_auditleri.sql");
const service = read("api/src/Services/Bildirim/GunlukBildirimDuzeltmeAuditService.php");
const controller = read("api/src/Controllers/BildirimlerController.php");

describe("migration 085: gunluk_bildirim_duzeltme_auditleri", () => {
  it("creates append-only audit table additively", () => {
    expect(migration).toContain("CREATE TABLE IF NOT EXISTS gunluk_bildirim_duzeltme_auditleri");
    expect(migration).toContain("eski_bildirim_turu");
    expect(migration).toContain("yeni_bildirim_turu");
    expect(migration).toContain("eski_alanlar JSON NOT NULL");
    expect(migration).toContain("yeni_alanlar JSON NOT NULL");
    expect(migration).toContain("correction_reason");
    expect(migration).toContain("actor_user_id");
    expect(migration).toContain("PACK085_BLOCKER");
    expect(migration).toContain("trg_gbda_no_update");
    expect(migration).toContain("trg_gbda_no_delete");
  });

  it("writes no business data / no drop table", () => {
    const statements = migration.replace(/--[^\n]*\n/g, "\n");
    expect(statements).not.toMatch(/\bINSERT\s+INTO\b/i);
    expect(statements).not.toMatch(/\bUPDATE\s+gunluk_bildirimler\b/i);
    expect(statements).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(statements).not.toMatch(/\bDROP\s+TABLE\b/i);
  });
});

describe("gunluk bildirim correction audit write owner", () => {
  it("wires transactional audit on update and cancel; skips request-correction", () => {
    expect(service).toContain("class GunlukBildirimDuzeltmeAuditService");
    expect(service).toContain("appendInTransaction");
    expect(service).toContain("listByBildirimId");
    expect(service).toContain("businessFieldsChanged");
    expect(controller).toContain("GunlukBildirimDuzeltmeAuditService::appendInTransaction");
    expect(controller).toContain("OLAY_DUZELTME");
    expect(controller).toContain("OLAY_IPTAL");
    expect(controller).toContain("duzeltme_gecmisi");
    expect(controller).toContain("businessFieldsChanged");
    const requestCorrectionBlock = controller.slice(
      controller.indexOf("function requestCorrection"),
      controller.indexOf("function cancel")
    );
    expect(requestCorrectionBlock).not.toContain("appendInTransaction");
    expect(controller).toContain("assertPeriodOpenForDate");
  });
});
