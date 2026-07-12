import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const controllerPath = resolve(
  process.cwd(),
  "api/src/Controllers/GenelYoneticiBildirimOnaylariController.php"
);
const routerPath = resolve(process.cwd(), "api/src/Router.php");
const controllerSource = readFileSync(controllerPath, "utf8");
const routerSource = readFileSync(routerPath, "utf8");

describe("GenelYoneticiBildirimOnaylariController source contract", () => {
  it("exposes summary, approve and detail operations", () => {
    expect(controllerSource).toMatch(/public static function summary\(/);
    expect(controllerSource).toMatch(/public static function approve\(/);
    expect(controllerSource).toMatch(/public static function detail\(/);
  });

  it("uses permission guards without hard-coded role checks", () => {
    expect(controllerSource).toContain("genel_yonetici_bildirim_onayi.view");
    expect(controllerSource).toContain("genel_yonetici_bildirim_onayi.approve");
    expect(controllerSource).not.toMatch(/\$user\['rol'\]/);
    expect(controllerSource).not.toMatch(/===\s*'GENEL_YONETICI'/);
  });

  it("validates S72 prerequisite and duplicate with stable codes", () => {
    expect(controllerSource).toContain("AYLIK_BILDIRIM_ONAYI_GEREKLI");
    expect(controllerSource).toContain("AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI");
    expect(controllerSource).toContain("EKSIK_HAFTA_VAR");
    expect(controllerSource).toContain("GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT");
    expect(controllerSource).toContain("ZATEN_ONAYLANDI");
  });

  it("wraps approve in a transaction and inserts only the new domain table", () => {
    expect(controllerSource).toContain("$pdo->beginTransaction()");
    expect(controllerSource).toContain("INSERT INTO genel_yonetici_bildirim_onaylari");
    expect(controllerSource).not.toContain("UPDATE aylik_bildirim_onaylari");
    expect(controllerSource).not.toContain("UPDATE gunluk_bildirimler");
    expect(controllerSource).not.toContain("UPDATE haftalik_bildirim_mutabakatlari");
    expect(controllerSource).not.toContain("aylik_ozet_satirlari");
  });
});

describe("Router source contract for genel yonetici bildirim onayi", () => {
  it("registers ozet before dynamic id route", () => {
    const ozetIndex = routerSource.indexOf("'/genel-yonetici-bildirim-onaylari/ozet'");
    const postIndex = routerSource.indexOf("'/genel-yonetici-bildirim-onaylari' && $method === 'POST'");
    const detailIndex = routerSource.indexOf("#^/genel-yonetici-bildirim-onaylari/(\\d+)$#");
    expect(ozetIndex).toBeGreaterThan(-1);
    expect(postIndex).toBeGreaterThan(ozetIndex);
    expect(detailIndex).toBeGreaterThan(postIndex);
  });
});
