import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("S98 SGK mapping + policy source guards", () => {
  it("migration 047 real decision contract present", () => {
    const names = readdirSync(resolve("api/migrations")).filter((n) => n.endsWith(".sql")).sort();
    expect(names.some((n) => /^047_sgk_real_decision_contract\.sql$/.test(n))).toBe(true);
    expect(read("api/migrations/047_sgk_real_decision_contract.sql")).toContain("MAZERET_IZNI");
    expect(read("api/migrations/047_sgk_real_decision_contract.sql")).toContain("KISMI_SURE_DEVAMSIZLIK");
    expect(read("api/src/Services/Payroll/SgkEslemeKararContract.php")).toContain("HER_ZAMAN_DAHIL");
    expect(read("api/src/Services/Payroll/SgkSurecEslemeImportValidator.php")).toContain("karar_kurali");
    expect(read("api/src/Services/Payroll/SgkSurecEslemeWriteService.php")).toContain("SUREC_ESLEME_DRAFT_ONAY");
    expect(read("api/src/Services/Payroll/SgkSirketPolitikaCatalog.php")).toContain("UCRET_MODELINE_GORE");
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
    expect(catalog).toContain("UCRET_MODELINE_GORE");
    expect(catalog).not.toContain("'default'");
  });

  it("S98-R1 decision enums and no-code DAHIL contract", () => {
    const contracts = read("api/src/Services/Payroll/SgkKatalogContracts.php");
    const karar = read("api/src/Services/Payroll/SgkEslemeKararContract.php");
    const validator = read("api/src/Services/Payroll/SgkSurecEslemeImportValidator.php");
    const engine = read("api/src/Services/Payroll/SgkPrimGunuEngine.php");
    const write = read("api/src/Services/Payroll/SgkSurecEslemeWriteService.php");
    expect(contracts).toContain("MAZERET_IZNI");
    expect(contracts).toContain("KISMI_SURE_DEVAMSIZLIK");
    expect(contracts).toContain("KOD_YOK");
    expect(karar).toContain("DAHIL_ILE_KOD_CELISKISI");
    expect(karar).toContain("UCRET_MODELINE_GORE");
    expect(karar).toContain("roundPartialPrimDays");
    expect(validator).toContain("karar_kurali");
    expect(validator).toContain("kod_secim_modu");
    expect(write).toContain("eksik_gun_kodu") && expect(write).toContain("null");
    expect(engine).toContain("S98R1_SGK_PRIM_GUNU_CONTRACT_V1");
    expect(engine).toContain("KISMI_SURE_DEVAMSIZLIK");
    expect(engine).toContain("kismi_aylik_calisma_saati");
  });

  it("mapping bootstrap stays PHP 7.4-safe and ships required require_once targets", () => {
    const bootstrapFiles = [
      "api/src/Services/Payroll/SgkEslemeKararContract.php",
      "api/src/Services/Payroll/SgkSurecEslemeImportValidator.php",
      "api/src/Services/Payroll/SgkKatalogContracts.php",
      "api/src/Http/CsvResponse.php",
    ];
    for (const path of bootstrapFiles) {
      const src = read(path);
      // Forbid PHP 8 match-expression form: `match (` (comments/docs may mention it).
      expect(src.replace(/\/\/.*$/gm, "").replace(/\/\*[\s\S]*?\*\//g, ""), path).not.toMatch(
        /\bmatch\s*\(/,
      );
      expect(src, path).not.toMatch(/\breadonly\s+/);
      expect(src, path).not.toMatch(/\benum\s+\w/);
    }
    const validator = read("api/src/Services/Payroll/SgkSurecEslemeImportValidator.php");
    expect(validator).toContain("require_once __DIR__ . '/SgkEslemeKararContract.php'");
    expect(validator).toContain("require_once __DIR__ . '/SgkKatalogContracts.php'");
    for (const rel of [
      "api/src/Services/Payroll/SgkEslemeKararContract.php",
      "api/src/Services/Payroll/SgkKatalogContracts.php",
      "api/src/Services/Payroll/SgkSurecEslemeImportValidator.php",
      "api/src/Http/CsvResponse.php",
      "api/src/Controllers/SgkKatalogHazirlikController.php",
      "api/src/Router.php",
    ]) {
      expect(read(rel).length).toBeGreaterThan(100);
    }
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
    expect(panel).toContain('data-testid="sgk-esleme-decision-rules-note"');
    expect(panel).toContain("Kod kullanılmaz");
    expect(panel).toContain("Ücret modeline göre");
    expect(api).toContain("downloadSgkSurecEslemeSablonCsv");
    expect(api).toContain("downloadSgkSirketPolitikasiSablonCsv");
    expect(api).toContain("surecEslemeSablonCsv");
    expect(api).toContain("sirketPolitikasiSablonCsv");
  });
});
