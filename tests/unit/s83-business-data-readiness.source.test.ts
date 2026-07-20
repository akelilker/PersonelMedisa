import { describe, expect, it } from "vitest";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

function hasPhpCli(): boolean {
  try {
    execFileSync("php", ["-v"], { encoding: "utf8" });
    return true;
  } catch {
    return false;
  }
}

describe("S83 bordro business data readiness sources", () => {
  it("preflight contract and readiness domains exist", () => {
    const preflight = readFileSync("api/src/Services/BordroHazirlikPreflightService.php", "utf8");
    expect(preflight).toContain("S83_BORDRO_BUSINESS_DATA_READINESS_V1");
    expect(preflight).toContain("readiness_domains");
    expect(preflight).toContain("candidate_gate");
    expect(preflight).toContain("kullanici_mesaji");
    expect(preflight).toContain("s81_final_onay");
    expect(preflight).toContain("net_maas");
    expect(preflight).toContain("bordro_devir");
    expect(preflight).toContain("/personeller/");
    expect(preflight).toContain("tab=genel-bilgiler");
    expect(preflight).not.toContain("tab=ucret");
    expect(preflight).toContain("/raporlar?panel=etki-adayi");
    expect(preflight).toContain("ONAY_TABLOSU_YOK");
    expect(preflight).toContain("ONAY_KAYDI_YOK");
  });

  it("controller exposes readiness endpoints before dynamic id routes", () => {
    const router = readFileSync("api/src/Router.php", "utf8");
    const readinessPos = router.indexOf("/bordro-hazirlik/readiness");
    const sablonPos = router.indexOf("/bordro-hazirlik/devirler/sablon.csv");
    const adayPos = router.indexOf("/bordro-hazirlik/adaylar/");
    expect(readinessPos).toBeGreaterThan(-1);
    expect(sablonPos).toBeGreaterThan(-1);
    expect(adayPos).toBeGreaterThan(sablonPos);
    expect(router).toContain("/bordro-hazirlik/net-maas-eksikleri");
    expect(router).toContain("/bordro-hazirlik/readiness/export.csv");
    expect(router).toContain("karar-ozeti");
  });

  it("devir import classification counts exist", () => {
    const service = readFileSync("api/src/Services/PersonelBordroDevirService.php", "utf8");
    expect(service).toContain("processImport");
    expect(service).toContain("eklenecek");
    expect(service).toContain("guncellenecek");
    expect(service).toContain("degismeyecek");
    expect(service).toContain("eslesmeyen");
    expect(service).toContain("duplicate");
    expect(service).toContain("scope_disi");
    expect(service).toContain("upsertInTransaction");
  });

  it("frontend veri-hazirlik tab and endpoints wired", () => {
    const page = readFileSync("src/features/raporlar/pages/BordroHazirlikMerkeziPage.tsx", "utf8");
    const api = readFileSync("src/api/bordro-hazirlik.api.ts", "utf8");
    const endpoints = readFileSync("src/api/endpoints.ts", "utf8");
    expect(page).toContain("veri-hazirlik");
    expect(page).toContain("bordro-readiness-domains");
    expect(page).toContain("candidate_gate");
    expect(page).toContain("bordro-readiness-csv-indir");
    expect(page).toContain("downloadBordroReadinessCsv");
    expect(page).toContain("bordro-readiness-eksik-kodlar");
    expect(page).toContain("finans.view");
    expect(page).toContain("bordro-on-izleme-finance-masked");
    expect(api).toContain("fetchBordroNetMaasEksikleri");
    expect(api).toContain("BordroCandidateGate");
    expect(endpoints).toContain("netMaasEksikleri");
    expect(endpoints).toContain("devirSablonCsv");
    expect(endpoints).toContain("kararOzeti");
  });

  it("on-izleme finance mask lives in API owner", () => {
    const controller = readFileSync("api/src/Controllers/BordroHazirlikController.php", "utf8");
    const service = readFileSync("api/src/Services/BordroOnIzlemeService.php", "utf8");
    expect(controller).toContain("finans.view");
    expect(controller).toContain("maskFinanceFields");
    expect(service).toContain("maskFinanceFields");
    expect(service).toContain("finance_masked");
  });

  it("keeps 034 as latest migration when no 035", () => {
    const migrations = readFileSync("api/migrations/034_bordro_onay_ve_projection.sql", "utf8");
    expect(migrations).toContain("bordro_onay_durumu");
  });
});

describe("S83 PHP runner", () => {
  it.skipIf(!hasPhpCli())("runs readiness projection and import classification checks", () => {
    const runner = path.resolve("tests/php/S83BordroBusinessDataReadinessTestRunner.php");
    const output = execFileSync("php", [runner], { encoding: "utf8" });
    expect(output).toContain("S83 PHP runner OK");
  });
});
