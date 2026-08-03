import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("S98 SGK mapping + policy source guards", () => {
  it("no migration 047+ and owners present", () => {
    const names = readdirSync(resolve("api/migrations")).filter((n) => n.endsWith(".sql")).sort();
    expect(names.some((n) => /^047_/.test(n))).toBe(false);
    expect(read("api/src/Services/Payroll/SgkSurecEslemeImportValidator.php")).toContain("dryRun");
    expect(read("api/src/Services/Payroll/SgkSurecEslemeWriteService.php")).toContain("SUREC_ESLEME_DRAFT_ONAY");
    expect(read("api/src/Services/Payroll/SgkSirketPolitikaCatalog.php")).toContain("SGK_ODENEK_MAHSUP_MODU");
    expect(read("api/src/Services/Payroll/SgkSirketPolitikaWriteService.php")).toContain("SGK_POLITIKA_DRAFT_ONAY");
  });

  it("dual-control and overlap guards in catalog approve path", () => {
    const write = read("api/src/Services/Payroll/SgkKatalogWriteService.php");
    const onay = read("api/src/Services/Payroll/SgkKatalogOnayService.php");
    const eslemeWrite = read("api/src/Services/Payroll/SgkSurecEslemeWriteService.php");
    expect(write).toContain("SGK_KATALOG_SELF_APPROVAL_DENIED");
    expect(write).toContain("SGK_KATALOG_TARIH_CAKISMA");
    expect(onay).toContain("SELF_APPROVAL");
    expect(eslemeWrite).toContain("Never touch parent");
  });

  it("routes and client endpoints wired", () => {
    const router = read("api/src/Router.php");
    const endpoints = read("src/api/endpoints.ts");
    const api = read("src/api/sgk-katalog-hazirlik.api.ts");
    expect(router).toContain("/sgk-katalog-hazirlik/surec-esleme/dry-run");
    expect(router).toContain("/sgk-katalog-hazirlik/surec-esleme/import");
    expect(router).toContain("/sgk-katalog-hazirlik/sirket-politikasi/approve");
    expect(endpoints).toContain("surecEslemeDryRun");
    expect(endpoints).toContain("sirketPolitikasiApprove");
    expect(api).toContain("dryRunSgkSurecEsleme");
    expect(api).toContain("approveSgkSirketPolitikasi");
  });

  it("GENEL_YONETICI write and parent immutability comments", () => {
    const eslemeWrite = read("api/src/Services/Payroll/SgkSurecEslemeWriteService.php");
    expect(eslemeWrite).toContain("GENEL_YONETICI");
    expect(eslemeWrite).toContain("Never touch parent");
    expect(eslemeWrite).toContain("parent_immutable_mi");
  });

  it("demo mock fail-closed for new routes", () => {
    const mock = read("src/api/sgk-katalog-hazirlik.mock.ts");
    const demo = read("src/api/mock-demo.ts");
    expect(mock).toContain("buildSgkSurecEslemeDryRunMock");
    expect(mock).toContain("apply_yapilabilir_mi: false");
    expect(demo).toContain("/sgk-katalog-hazirlik/surec-esleme/dry-run");
    expect(demo).toContain("/sgk-katalog-hazirlik/sirket-politikasi/dry-run");
  });

  it("policy catalog documents bildirim_donem_tipi column not degerler code", () => {
    const catalog = read("api/src/Services/Payroll/SgkSirketPolitikaCatalog.php");
    expect(catalog).toContain("bildirim_donem_tipi");
    expect(catalog).toContain("sgk_sirket_politika_surumleri");
    expect(catalog).not.toContain("'default'");
  });

  it("UI panel wires S98 testids, AppActionDialog, and download helpers", () => {
    const panel = read("src/features/raporlar/components/SgkKatalogHazirlikPanel.tsx");
    const api = read("src/api/sgk-katalog-hazirlik.api.ts");
    expect(panel).toContain("AppActionDialog");
    expect(panel).not.toContain("window.confirm");
    expect(panel).toContain('data-testid="sgk-esleme-sablon-download"');
    expect(panel).toContain('data-testid="sgk-esleme-dry-run"');
    expect(panel).toContain('data-testid="sgk-esleme-draft"');
    expect(panel).toContain('data-testid="sgk-esleme-submit"');
    expect(panel).toContain('data-testid="sgk-esleme-approve"');
    expect(panel).toContain('data-testid="sgk-politika-sablon-download"');
    expect(panel).toContain('data-testid="sgk-politika-dry-run"');
    expect(panel).toContain('data-testid="sgk-politika-draft"');
    expect(panel).toContain('data-testid="sgk-politika-submit"');
    expect(panel).toContain('data-testid="sgk-politika-approve"');
    expect(panel).toContain("SUREC_ESLEME_DRAFT_ONAY");
    expect(panel).toContain("SGK_POLITIKA_DRAFT_ONAY");
    expect(panel).toContain("hasRole(\"GENEL_YONETICI\")");
    expect(panel).toContain("dryRunSgkSurecEsleme");
    expect(panel).toContain("importSgkSurecEsleme");
    expect(panel).toContain("submitSgkKatalog");
    expect(panel).toContain("approveSgkKatalog");
    expect(panel).toContain("dryRunSgkSirketPolitikasi");
    expect(panel).toContain("importSgkSirketPolitikasi");
    expect(panel).toContain("submitSgkSirketPolitikasi");
    expect(panel).toContain("approveSgkSirketPolitikasi");
    expect(api).toContain("downloadSgkSurecEslemeSablonCsv");
    expect(api).toContain("downloadSgkSirketPolitikasiSablonCsv");
    expect(api).toContain("surecEslemeSablonCsv");
    expect(api).toContain("sirketPolitikasiSablonCsv");
  });
});
