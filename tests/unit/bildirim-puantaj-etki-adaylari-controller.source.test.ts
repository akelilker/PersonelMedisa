import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const controllerPath = resolve(
  process.cwd(),
  "api/src/Controllers/BildirimPuantajEtkiAdaylariController.php"
);
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const rolePermissionsPath = resolve(process.cwd(), "api/src/Auth/RolePermissions.php");
const policyPath = resolve(process.cwd(), "api/src/Services/BildirimPuantajEtkiDecisionPolicy.php");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");
const rolePermissionsSource = readFileSync(rolePermissionsPath, "utf8");
const policySource = readFileSync(policyPath, "utf8");

describe("BildirimPuantajEtkiAdaylariController source contract", () => {
  it("exposes summary, list, detail, generate, dismiss and apply operations", () => {
    expect(controllerSource).toMatch(/public static function summary\(/);
    expect(controllerSource).toMatch(/public static function list\(/);
    expect(controllerSource).toMatch(/public static function detail\(/);
    expect(controllerSource).toMatch(/public static function generate\(/);
    expect(controllerSource).toMatch(/public static function dismiss\(/);
    expect(controllerSource).toMatch(/public static function apply\(/);
  });

  it("uses permission guards without hard-coded role checks", () => {
    expect(controllerSource).toContain("puantaj.bildirim_etki.view");
    expect(controllerSource).toContain("puantaj.bildirim_etki.generate");
    expect(controllerSource).toContain("BildirimPuantajEtkiDecisionPolicy::PERMISSION_DISMISS");
    expect(controllerSource).toContain("BildirimPuantajEtkiDecisionPolicy::PERMISSION_APPLY");
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

  it("implements dismiss and apply transitions with shared decision policy", () => {
    expect(controllerSource).toMatch(/public static function dismiss\(/);
    expect(controllerSource).toMatch(/public static function apply\(/);
    expect(controllerSource).toContain("UPDATE ' . self::TABLE");
    expect(controllerSource).toContain("BildirimPuantajEtkiDecisionPolicy");
    expect(controllerSource).toContain("BildirimPuantajEtkiApplyService::apply");
    expect(controllerSource).toContain("validateApplyExpectedState");
    expect(controllerSource).toContain("mapApplyResponse");
  });

  it("maps locked karar audit fields in list/detail", () => {
    expect(controllerSource).toContain("mapKararListFields");
    expect(controllerSource).toContain("mapKararDetailFields");
    for (const field of [
      "karar_zamani",
      "karar_veren_user_id",
      "karar_gerekcesi",
      "uygulanan_puantaj_id",
      "onceki_puantaj_snapshot",
      "sonraki_puantaj_snapshot",
      "uygulama_hash",
    ]) {
      expect(controllerSource).toContain(field);
    }

    const listMapperMatch = controllerSource.match(
      /private static function mapKararListFields[\s\S]*?^    \}/m
    );
    const detailMapperMatch = controllerSource.match(
      /private static function mapKararDetailFields[\s\S]*?^    \}/m
    );
    expect(listMapperMatch).not.toBeNull();
    expect(detailMapperMatch).not.toBeNull();
    const listMapper = listMapperMatch![0];
    const detailMapper = detailMapperMatch![0];

    for (const forbidden of [
      "karar_turu",
      "blok_nedeni",
      "uygulanabilir_mi",
      "yok_sayilabilir_mi",
      "karar_blok_nedeni",
    ]) {
      expect(listMapper).not.toContain(forbidden);
      expect(detailMapper).not.toContain(forbidden);
    }
    expect(controllerSource).toContain("BildirimPuantajEtkiDecisionPolicy");
  });
});

describe("Router source contract for puantaj bildirim etki adaylari", () => {
  it("registers ozet, hazirla, yok-say and uygula before dynamic id route", () => {
    const ozetIndex = routerSource.indexOf("'/puantaj/bildirim-etki-adaylari/ozet'");
    const hazirlaIndex = routerSource.indexOf("'/puantaj/bildirim-etki-adaylari/hazirla'");
    const listIndex = routerSource.indexOf("'/puantaj/bildirim-etki-adaylari' && $method === 'GET'");
    const yokSayIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)/yok-say$#");
    const uygulaIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)/uygula$#");
    const detailIndex = routerSource.indexOf("#^/puantaj/bildirim-etki-adaylari/(\\d+)$#");
    expect(ozetIndex).toBeGreaterThan(-1);
    expect(hazirlaIndex).toBeGreaterThan(ozetIndex);
    expect(listIndex).toBeGreaterThan(hazirlaIndex);
    expect(yokSayIndex).toBeGreaterThan(listIndex);
    expect(uygulaIndex).toBeGreaterThan(yokSayIndex);
    expect(detailIndex).toBeGreaterThan(uygulaIndex);
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

describe("S74-C1 backend permission matrix (RolePermissions.php)", () => {
  function roleBlock(role: string): string {
    const start = rolePermissionsSource.indexOf(`'${role}' => [`);
    expect(start).toBeGreaterThan(-1);
    const end = rolePermissionsSource.indexOf("],", start);
    return rolePermissionsSource.slice(start, end);
  }

  it("MUHASEBE apply yes / dismiss yes", () => {
    const block = roleBlock("MUHASEBE");
    expect(block).toContain("'puantaj.bildirim_etki.apply'");
    expect(block).toContain("'puantaj.bildirim_etki.dismiss'");
  });

  it("GENEL_YONETICI apply no / dismiss no", () => {
    const block = roleBlock("GENEL_YONETICI");
    expect(block).not.toContain("'puantaj.bildirim_etki.apply'");
    expect(block).not.toContain("'puantaj.bildirim_etki.dismiss'");
  });

  it("BOLUM_YONETICISI apply no / dismiss no", () => {
    const block = roleBlock("BOLUM_YONETICISI");
    expect(block).not.toContain("'puantaj.bildirim_etki.apply'");
    expect(block).not.toContain("'puantaj.bildirim_etki.dismiss'");
  });

  it("BIRIM_AMIRI apply no / dismiss no", () => {
    const block = roleBlock("BIRIM_AMIRI");
    expect(block).not.toContain("'puantaj.bildirim_etki.apply'");
    expect(block).not.toContain("'puantaj.bildirim_etki.dismiss'");
  });

  it("PATRON apply no / dismiss no", () => {
    const block = roleBlock("PATRON");
    expect(block).not.toContain("'puantaj.bildirim_etki.apply'");
    expect(block).not.toContain("'puantaj.bildirim_etki.dismiss'");
  });
});

describe("BildirimPuantajEtkiDecisionPolicy source contract", () => {
  it("defines locked state machine and action mapping", () => {
    expect(policySource).toContain("evaluateApply");
    expect(policySource).toContain("evaluateDismiss");
    expect(policySource).toContain("validateExpectedState");
    expect(policySource).toContain("isApplyAllowed");
    expect(policySource).toContain("isDismissAllowed");
    expect(policySource).toContain("'HAZIR'");
    expect(policySource).toContain("'INCELEME_GEREKLI'");
    expect(policySource).toContain("'UYGULANDI'");
    expect(policySource).toContain("'YOK_SAYILDI'");
    expect(policySource).not.toContain("validateDismissReason");
    expect(policySource).not.toContain("kararTuruForAction");
    expect(policySource).not.toContain("MANUEL_INCELEME");
    expect(policySource).not.toContain("MIN_DISMISS_REASON_LENGTH");
  });
});
