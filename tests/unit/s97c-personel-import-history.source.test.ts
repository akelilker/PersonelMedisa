import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();

function read(path: string) {
  return readFileSync(resolve(root, path), "utf8");
}

describe("S97-C personel import history source locks", () => {
  it("wires read-only history endpoints without writes or migration", () => {
    const endpoints = read("src/api/endpoints.ts");
    const router = read("api/src/Router.php");
    const history = read("api/src/Services/Personel/PersonelImportHistoryService.php");
    const status = read("api/src/Services/Personel/PersonelImportHistoryStatus.php");
    const controller = read("api/src/Controllers/PersonellerController.php");
    const page = read("src/features/personeller/pages/PersonellerPage.tsx");
    const modal = read("src/features/personeller/components/PersonelImportHistoryModal.tsx");

    expect(endpoints).toContain('importRuns: "/personeller/import/runs"');
    expect(endpoints).toContain("importRunDetail:");
    expect(endpoints).toContain("importRunEvidenceCsv:");
    expect(router).toContain("/personeller/import/runs");
    expect(router).toContain("evidence\\.csv");
    expect(router).toContain("importRunsList");
    expect(router).toContain("importRunDetail");
    expect(router).toContain("importRunEvidenceCsv");
    expect(controller).toContain("importRunsList");
    expect(controller).toContain("PersonelImportHistoryService::listRuns");
    expect(history).toContain("fingerprintIdempotencyKey");
    expect(history).toContain("next_cursor");
    expect(history).toContain("SCHEMA_NOT_READY");
    expect(history).toContain("MAX_LIMIT = 100");
    expect(history).toContain("DEFAULT_LIMIT = 25");
    expect(history).toContain("MAX_DETAIL_ROWS = 500");
    expect(history).toContain("CURSOR_VERSION");
    expect(history).toContain("LOWER(LEFT(SHA2(r.idempotency_key, 256), 12))");
    expect(history).not.toContain("UPDATE personel_import_runs");
    expect(history).not.toContain("DELETE FROM personel_import");
    expect(history).not.toContain("INSERT INTO personel_import");
    expect(history).not.toMatch(/^\s*r\.idempotency_key\s*,/m);
    expect(status).toContain("COMPLETED");
    expect(status).toContain("BASARISIZ");
    expect(status).toContain("CLAIMED");
    expect(status).not.toContain("'FAILED'");
    expect(page).toContain("personeller-import-history-open");
    expect(page).toContain("Import Geçmişi");
    expect(page).toContain("canApplyPersonelImport");
    expect(modal).toContain(
      "Henüz tamamlanmış veya başarısız bir personel import işlemi bulunmuyor."
    );
    expect(modal).toContain("Kanıt CSV indir");
    expect(modal).not.toContain("Retry import");
    expect(modal).not.toContain("Tekrar çalıştır");
    expect(modal).not.toContain("Personelleri Sisteme Aktar");
  });

  it("keeps list query join shape free of N+1 and raw PII response fields", () => {
    const history = read("api/src/Services/Personel/PersonelImportHistoryService.php");
    const router = read("api/src/Router.php");
    expect(history).toContain("LEFT JOIN users u ON u.id = r.actor_id");
    expect(history).toContain("LEFT JOIN subeler s ON s.id = r.active_sube_id");
    expect(history).toContain("ORDER BY r.started_at DESC, r.id DESC");
    expect(history).not.toContain("SELECT * FROM personel_import_runs");
    expect(history).not.toContain("AS tc_sha256");
    expect(history).toContain("idempotency_fingerprint");
    expect(history).toContain("LOWER(LEFT(SHA2(r.idempotency_key, 256), 12)) AS idempotency_fingerprint");
    expect(history).not.toContain("'idempotency_key' =>");
    expect(router).not.toMatch(/import\/runs[^\n]{0,80}POST/);
    expect(router).not.toContain("import/runs/{id}/retry");
    expect(history).not.toMatch(/SELECT[\s\S]{0,400}AS tc_sha256/);
  });

  it("does not add import_history migration", () => {
    const files = readdirSync(resolve(root, "api/migrations"));
    expect(files.some((name) => String(name).includes("import_history"))).toBe(false);
    expect(files.some((name) => String(name).startsWith("047_sgk_real_decision_contract"))).toBe(true);
  });
});
