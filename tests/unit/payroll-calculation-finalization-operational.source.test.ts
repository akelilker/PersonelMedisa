import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { execFileSync } from "node:child_process";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

function read(rel: string) {
  return readFileSync(resolve(root, rel), "utf8");
}

function hasPhpCli(): boolean {
  try {
    execFileSync("php", ["-v"], { encoding: "utf8" });
    return true;
  } catch {
    return false;
  }
}

describe("Payroll calculation finalization operational close", () => {
  it("role chain: IK hesaplama, MUHASEBE preview/export, GENEL kesinleştir, BA/PERSONEL deny", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "maas_hesaplama.manage")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "maas_hesaplama_adaylari.manage")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "bordro_kesinlestirme.approve")).toBe(false);

    expect(hasRolePermission("MUHASEBE", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "maas_hesaplama.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "maas_hesaplama_adaylari.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "maas_hesaplama_adaylari.manage")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "bordro_kesinlestirme.approve")).toBe(false);

    expect(hasRolePermission("GENEL_YONETICI", "bordro_kesinlestirme.approve")).toBe(true);

    expect(hasRolePermission("BIRIM_AMIRI", "bordro_on_izleme.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "maas_hesaplama.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "bordro_on_izleme.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "maas_hesaplama.view")).toBe(false);
  });

  it("canonical preflight promotes operasyonel açık kayıt to BLOCKER before hesaplanabilir", () => {
    const preflight = read("api/src/Services/BordroHazirlikPreflightService.php");
    const buildIdx = preflight.indexOf("function build(");
    const blockerCountIdx = preflight.indexOf("$blockerCount = self::countSeverity($items, 'BLOCKER')", buildIdx);
    const operasyonelIdx = preflight.indexOf("BordroOperasyonelHazirlikService::build", buildIdx);
    const opBlockerIdx = preflight.indexOf("OPERASYONEL_HAZIRLIK_EKSIK", buildIdx);
    expect(operasyonelIdx).toBeGreaterThan(buildIdx);
    expect(opBlockerIdx).toBeGreaterThan(operasyonelIdx);
    expect(blockerCountIdx).toBeGreaterThan(opBlockerIdx);
    expect(preflight).toContain("operasyonel_puantaj");
    expect(preflight).toContain("sessizce hazır kabul edilmez");
  });

  it("finalization owner reuses preflight gate, idempotent re-kesinleştir, PERIOD_REOPENED", () => {
    const onIzleme = read("api/src/Services/BordroOnIzlemeService.php");
    const controller = read("api/src/Controllers/BordroHazirlikController.php");
    expect(onIzleme).toContain("function kesinlestir");
    expect(onIzleme).toContain("KESINLESTI");
    expect(onIzleme).toContain("PERIOD_REOPENED");
    expect(onIzleme).toContain("isPeriodReopened");
    expect(onIzleme).toContain("OPERASYONEL_HAZIRLIK_EKSIK");
    expect(onIzleme).toContain("Idempotent guard");
    expect(controller).toContain("bordro_kesinlestirme.approve");
    // No production mutation helpers invented here.
    expect(onIzleme).not.toContain("CREATE TABLE");
  });

  it("preview/export parity endpoint reuses on-izleme projection under bordro_on_izleme.view", () => {
    const controller = read("api/src/Controllers/BordroHazirlikController.php");
    const router = read("api/src/Router.php");
    const api = read("src/api/bordro-hazirlik.api.ts");
    const endpoints = read("src/api/endpoints.ts");
    const page = read("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    expect(controller).toContain("onIzlemeExportCsv");
    expect(controller).toContain("buildDonemOzeti");
    expect(controller).toContain("bordro_on_izleme.view");
    expect(router).toContain("/bordro-hazirlik/on-izleme/export.csv");
    expect(endpoints).toContain("onIzlemeExportCsv");
    expect(api).toContain("downloadBordroOnIzlemeCsv");
    expect(page).toContain("bordro-on-izleme-csv-indir");
    expect(page).toContain("downloadBordroOnIzlemeCsv");
  });

  it("period/scope parity: embedded Maas Hesaplama locks parent yıl/ay/şube", () => {
    const maas = read("src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx");
    const bordro = read("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    expect(maas).toContain("lockedFilters");
    expect(maas).toContain("maas-hesaplama-period-locked-note");
    expect(bordro).toContain("lockedFilters={{ ay: filters.ay, subeId: filters.subeId }}");
  });

  it("mid-period exit retained in net-maaş and devir period roster (not AKTIF-only)", () => {
    const preflight = read("api/src/Services/BordroHazirlikPreflightService.php");
    const controller = read("api/src/Controllers/BordroHazirlikController.php");
    const listFn = preflight.slice(preflight.indexOf("function listNetMaasEksikleri"));
    const listWhere = listFn.slice(0, listFn.indexOf("function classifyNetMaasDurumu"));
    expect(listWhere).not.toContain("aktif_durum = 'AKTIF'");
    expect(listWhere).toContain("Period roster (not AKTIF-only)");
    expect(preflight).toContain("resolveOperationalPersonnelSet");
    expect(controller).toContain("resolveOperationalPersonnelSet");
    const enrich = controller.slice(controller.indexOf("function enrichDevirler"));
    expect(enrich).not.toContain("aktif_durum = 'AKTIF'");
  });

  it("UI surfaces Turkish readiness / kesinleştir blocker copy for Seda and Muhasebe", () => {
    const page = read("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    expect(page).toContain("bordro-operasyonel-blocker-mesaj");
    expect(page).toContain("bordro-kesinlestir-blocked-note");
    expect(page).toContain("Mühürlü");
    expect(page).toContain("Yeniden açma bekliyor");
    expect(page).not.toContain("OPEN (ACIK)");
    expect(page).not.toContain("Salt okunur (SEALED / REOPEN_PENDING)");
  });

  it("preserves PR #264 operasyonel owner and PR #263 Bugün precedence", () => {
    const prep = read("tests/unit/ik-payroll-preparation-operational.source.test.ts");
    expect(prep).toContain("BordroOperasyonelHazirlikService");
    expect(prep).toContain("BugunPersonelDurumuService::resolvePersonDurum");
    const op = read("api/src/Services/BordroOperasyonelHazirlikService.php");
    expect(op).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(op).toContain("effectiveExceptionTur");
    expect(op).toContain("ISTEN_AYRILMA");
  });

  it("does not invent new permission or payroll formula / migration", () => {
    const preflight = read("api/src/Services/BordroHazirlikPreflightService.php");
    const onIzleme = read("api/src/Services/BordroOnIzlemeService.php");
    const page = read("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    expect(preflight).not.toContain("CREATE TABLE");
    expect(onIzleme).not.toContain("CREATE TABLE");
    expect(page).not.toContain("muhurleAylik");
    expect(page).toContain("kesinlestirBordro");
    // Existing permissions only
    expect(hasRolePermission("MUHASEBE", "bordro_on_izleme.view")).toBe(true);
  });

  it("PHP readiness + finalization semantics runner passes when php is available", () => {
    if (!hasPhpCli()) {
      return;
    }
    const out = execFileSync("php", ["tests/php/BordroOperasyonelHazirlikPhpTestRunner.php"], {
      encoding: "utf8",
      cwd: root
    });
    expect(out).toContain("Bordro operasyonel hazirlik PHP runner OK");
    const out2 = execFileSync("php", ["tests/php/PayrollCalculationFinalizationPhpTestRunner.php"], {
      encoding: "utf8",
      cwd: root
    });
    expect(out2).toContain("Payroll calculation finalization PHP runner OK");
  });
});
