import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const controllerPath = resolve(
  process.cwd(),
  "api/src/Controllers/BildirimPuantajEtkiAdaylariController.php"
);
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const rolePermissionsPath = resolve(process.cwd(), "api/src/Auth/RolePermissions.php");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");
const rolePermissionsSource = readFileSync(rolePermissionsPath, "utf8");

describe("BildirimPuantajEtkiAdaylariController source contract", () => {
  it("exposes summary, list, detail and generate operations", () => {
    expect(controllerSource).toMatch(/public static function summary\(/);
    expect(controllerSource).toMatch(/public static function list\(/);
    expect(controllerSource).toMatch(/public static function detail\(/);
    expect(controllerSource).toMatch(/public static function generate\(/);
  });

  it("uses permission guards without hard-coded role checks", () => {
    expect(controllerSource).toContain("puantaj.bildirim_etki.view");
    expect(controllerSource).toContain("puantaj.bildirim_etki.generate");
    expect(controllerSource).not.toMatch(/\$user\['rol'\]/);
    expect(controllerSource).not.toMatch(/===\s*'MUHASEBE'/);
  });

  it("summary accepts only genel_yonetici_bildirim_onayi_id query param", () => {
    const summaryBody = controllerSource.match(/public static function summary\([\s\S]*?\n    \}/)?.[0] ?? "";
    expect(summaryBody).toContain("requireGyId($request->getQuery('genel_yonetici_bildirim_onayi_id'))");
    expect(summaryBody).not.toContain("getQuery('ay'");
    expect(summaryBody).not.toContain("requireScope");
    expect(summaryBody).not.toContain("requireAmirId");
  });

  it("derives generate and summary context from gy onay id via fetchGyById", () => {
    expect(controllerSource).toContain("fetchGyById");
    expect(controllerSource).toContain("fetchEligibleSources");
    expect(controllerSource).not.toContain("UPDATE gunluk_bildirimler");
    expect(controllerSource).not.toContain("UPDATE gunluk_puantaj");
    expect(controllerSource).not.toContain("INSERT INTO gunluk_puantaj");
    expect(controllerSource).not.toContain("INSERT INTO ek_odeme_kesinti");
    expect(controllerSource).toContain("created_by");
    expect(controllerSource).not.toContain("olusturan_user_id");
  });

  it("applies timestamp cutoff and HAFTALIK_MUTABAKATA_ALINDI source filter", () => {
    expect(controllerSource).toContain("created_at <= :cutoff_created");
    expect(controllerSource).toContain("updated_at <= :cutoff_updated");
    expect(controllerSource).toContain("'HAFTALIK_MUTABAKATA_ALINDI'");
    expect(controllerSource).toContain("ONAYLI_GUNLUK_BILDIRIM_BULUNAMADI");
    expect(controllerSource).toContain("PERIOD_LOCKED");
  });

  it("persists canonical migration columns on insert", () => {
    for (const column of [
      "etki_miktari",
      "etki_birimi",
      "conflict_detail",
      "bildirim_alt_tur",
      "bildirim_dakika",
      "source_priority",
      "mevcut_puantaj_id",
      "resmi_surec_id"
    ]) {
      expect(controllerSource).toContain(column);
    }
  });

  it("does not implement apply or resolve transitions", () => {
    expect(controllerSource).not.toMatch(/public static function apply\(/);
    expect(controllerSource).not.toMatch(/public static function resolve\(/);
    expect(controllerSource).not.toContain("UPDATE onayli_bildirim_puantaj_etki_adaylari");
  });
});

describe("Router source contract for puantaj bildirim etki adaylari", () => {
  it("registers ozet and hazirla before dynamic id route", () => {
    const ozetIndex = routerSource.indexOf("'/puantaj/bildirim-etki-adaylari/ozet'");
    const hazirlaIndex = routerSource.indexOf("'/puantaj/bildirim-etki-adaylari/hazirla'");
    const listIndex = routerSource.indexOf("'/puantaj/bildirim-etki-adaylari' && $method === 'GET'");
    const detailIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)$#");
    expect(ozetIndex).toBeGreaterThan(-1);
    expect(hazirlaIndex).toBeGreaterThan(ozetIndex);
    expect(listIndex).toBeGreaterThan(hazirlaIndex);
    expect(detailIndex).toBeGreaterThan(listIndex);
  });
});

describe("S74-B backend permission matrix (RolePermissions.php)", () => {
  function roleBlock(role: string): string {
    const start = rolePermissionsSource.indexOf(`'${role}' => [`);
    expect(start).toBeGreaterThan(-1);
    const end = rolePermissionsSource.indexOf("],", start);
    return rolePermissionsSource.slice(start, end);
  }

  it("GENEL_YONETICI view yes / generate no", () => {
    const block = roleBlock("GENEL_YONETICI");
    expect(block).toContain("'puantaj.bildirim_etki.view'");
    expect(block).not.toContain("'puantaj.bildirim_etki.generate'");
  });

  it("BOLUM_YONETICISI view yes / generate no", () => {
    const block = roleBlock("BOLUM_YONETICISI");
    expect(block).toContain("'puantaj.bildirim_etki.view'");
    expect(block).not.toContain("'puantaj.bildirim_etki.generate'");
  });

  it("MUHASEBE view yes / generate yes", () => {
    const block = roleBlock("MUHASEBE");
    expect(block).toContain("'puantaj.bildirim_etki.view'");
    expect(block).toContain("'puantaj.bildirim_etki.generate'");
  });

  it("BIRIM_AMIRI view no / generate no", () => {
    const block = roleBlock("BIRIM_AMIRI");
    expect(block).not.toContain("'puantaj.bildirim_etki.view'");
    expect(block).not.toContain("'puantaj.bildirim_etki.generate'");
  });

  it("PATRON view no / generate no", () => {
    const block = roleBlock("PATRON");
    expect(block).not.toContain("'puantaj.bildirim_etki.view'");
    expect(block).not.toContain("'puantaj.bildirim_etki.generate'");
  });
});
