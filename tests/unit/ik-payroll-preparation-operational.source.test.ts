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

describe("IK payroll preparation operational close", () => {
  it("IK can access bordro hazırlık; BIRIM_AMIRI and PERSONEL cannot", () => {
    expect(hasRolePermission("IK_SORUMLUSU", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "maas_hesaplama.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "bordro_on_izleme.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "bordro_kesinlestirme.approve")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "bordro_kesinlestirme.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "bordro_on_izleme.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "bordro_on_izleme.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "maas_hesaplama.view")).toBe(false);
    expect(hasRolePermission("PERSONEL", "maas_hesaplama.view")).toBe(false);
  });

  it("operasyonel hazirlik owner reuses Bugün precedence and period person set", () => {
    const service = read("api/src/Services/BordroOperasyonelHazirlikService.php");
    expect(service).toContain("S96_BORDRO_OPERASYONEL_HAZIRLIK_V1");
    expect(service).toContain("BugunPersonelDurumuService::resolvePersonDurum");
    expect(service).toContain("BugunPersonelDurumuService::effectiveExceptionTur");
    expect(service).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(service).toContain("isMissingEntryEvidence");
    expect(service).toContain("PUANTAJ_KONTROL_BEKLIYOR");
    expect(service).toContain("CANDIDATE_HAZIR_PENDING");
    expect(service).toContain("resolveOperationalPersonnelSet");
    expect(service).toContain("ISTEN_AYRILMA");
    expect(service).toContain("PuantajDonemPeriodService::resolvePeriodState");
    expect(service).toContain("isWriteLocked");
    expect(service).not.toContain("CREATE TABLE");
  });

  it("preflight and controller expose operasyonel özet under bordro_on_izleme.view", () => {
    const preflight = read("api/src/Services/BordroHazirlikPreflightService.php");
    const controller = read("api/src/Controllers/BordroHazirlikController.php");
    const router = read("api/src/Router.php");
    expect(preflight).toContain("operasyonel_hazirlik");
    expect(preflight).toContain("BordroOperasyonelHazirlikService::build");
    expect(controller).toContain("operasyonelOzet");
    expect(controller).toContain("bordro_on_izleme.view");
    expect(router).toContain("/bordro-hazirlik/operasyonel-ozet");
    const readinessPos = router.indexOf("/bordro-hazirlik/readiness");
    const opPos = router.indexOf("/bordro-hazirlik/operasyonel-ozet");
    const adayPos = router.indexOf("/bordro-hazirlik/adaylar/");
    expect(opPos).toBeGreaterThan(readinessPos);
    expect(adayPos).toBeGreaterThan(opPos);
  });

  it("Bordro Hazırlık UI shows period + ready/kontrol özet + problem list links", () => {
    const page = read("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    const api = read("src/api/bordro-hazirlik.api.ts");
    const endpoints = read("src/api/endpoints.ts");
    expect(page).toContain("bordro-operasyonel-hazirlik");
    expect(page).toContain("bordro-kontrol-gerekenler");
    expect(page).toContain("dispatchOpenBugunPersonelDurumu");
    expect(page).toContain("bordro-op-hazir-personel");
    expect(page).toContain("bordro-op-kontrol-personel");
    expect(page).toContain("period_state");
    expect(page).toContain("read_only");
    expect(api).toContain("operasyonel_hazirlik");
    expect(api).toContain("BordroOperasyonelHazirlikOzet");
    expect(endpoints).toContain("operasyonelOzet");
  });

  it("does not open new close/finalize button or third payroll screen", () => {
    const page = read("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx");
    expect(page).toContain("BordroHazirlikMerkeziPage");
    expect(page).not.toContain("muhurleAylik");
    expect(page).not.toContain("donem-kapanis-muhur");
    expect(page).toContain("kesinlestirBordro");
  });

  it("preserves PR263 Bugün precedence, PR261 leave workflow, migration 085 audit owner", () => {
    const bugun = read("api/src/Services/Bildirim/BugunPersonelDurumuService.php");
    expect(bugun).toContain("HENUZ_DEGERLENDIRILMEDI");
    expect(bugun).toContain("never auto-GELMEDI");
    expect(bugun).toContain("effectiveExceptionTur");
    const leave = read("tests/unit/personnel-leave-report-absence-operational.source.test.ts");
    expect(leave).toContain("surecler.create");
    const audit = read("tests/unit/migration-085-gunluk-bildirim-duzeltme-audit.source.test.ts");
    expect(audit).toContain("085_gunluk_bildirim_duzeltme_auditleri");
    const op = read("api/src/Services/BordroOperasyonelHazirlikService.php");
    expect(op).toContain("gunluk_bildirimler");
    expect(op).toContain("IPTAL");
    expect(op).toContain("state <>");
  });

  it("PHP readiness semantics runner passes when php is available", () => {
    if (!hasPhpCli()) {
      return;
    }
    const out = execFileSync("php", ["tests/php/BordroOperasyonelHazirlikPhpTestRunner.php"], {
      encoding: "utf8",
      cwd: root
    });
    expect(out).toContain("Bordro operasyonel hazirlik PHP runner OK");
  });
});
