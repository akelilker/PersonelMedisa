import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const reportServiceSource = readFileSync(
  resolve(root, "api/src/Services/BildirimPuantajEtkiRaporQueryService.php"),
  "utf8"
);
const controllerSource = readFileSync(
  resolve(root, "api/src/Controllers/BildirimPuantajEtkiAdaylariController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
const rolePermissionsSource = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");

describe("S76 BildirimPuantajEtkiRapor source contract", () => {
  it("exposes query and export owners with pagination", () => {
    expect(reportServiceSource).toMatch(/public static function query\(/);
    expect(reportServiceSource).toMatch(/public static function exportRows\(/);
    expect(reportServiceSource).toContain("buildSummary");
    expect(reportServiceSource).toContain("MAX_LIMIT");
  });

  it("reuses S74/S75 effective payload resolver via BildirimPuantajEtkiPuantajMapper", () => {
    expect(reportServiceSource).toContain("BildirimPuantajEtkiPuantajMapper::withEffectiveEtkiPayload");
    expect(reportServiceSource).toContain("source_integrity");
    expect(reportServiceSource).toContain("audit_integrity");
    expect(reportServiceSource).toContain("bildirim_puantaj_etki_cakisma_cozumleri");
  });

  it("supports report filters and summary counters", () => {
    for (const filter of [
      "sube_id",
      "donem",
      "departman_id",
      "personel_id",
      "state",
      "conflict_code",
      "etki_turu",
      "uygulama_modu",
      "projection_version"
    ]) {
      expect(reportServiceSource).toContain(filter);
    }
    for (const counter of ["HAZIR", "INCELEME_GEREKLI", "UYGULANDI", "YOK_SAYILDI"]) {
      expect(reportServiceSource).toContain(counter);
    }
  });

  it("registers rapor endpoints and export permission", () => {
    expect(routerSource).toContain("/puantaj/bildirim-etki-adaylari/rapor");
    expect(routerSource).toContain("/puantaj/bildirim-etki-adaylari/rapor/export.csv");
    expect(controllerSource).toMatch(/public static function report\(/);
    expect(controllerSource).toMatch(/public static function reportExportCsv\(/);
    expect(rolePermissionsSource).toContain("puantaj.bildirim_etki.rapor.view");
    expect(rolePermissionsSource).toContain("puantaj.bildirim_etki.rapor.export");
  });

  it("uses permission guards without hard-coded role checks in report controller methods", () => {
    const report = controllerSource.match(/public static function report\([\s\S]*?\n    \}/)?.[0] ?? "";
    expect(report).toContain("puantaj.bildirim_etki.rapor.view");
    expect(report).not.toMatch(/===\s*'MUHASEBE'/);
  });
});
