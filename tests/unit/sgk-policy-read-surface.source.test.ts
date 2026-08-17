import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const controller = readFileSync("api/src/Controllers/SgkKatalogHazirlikController.php", "utf8");
const readService = readFileSync("api/src/Services/Payroll/SgkSirketPolitikaReadService.php", "utf8");
const runtime = readFileSync("api/src/Services/SgkPrimGunuService.php", "utf8");
const router = readFileSync("api/src/Router.php", "utf8");

describe("SGK approved policy read surface", () => {
  it("uses the canonical authenticated read permission and route", () => {
    expect(controller).toContain("sirketPolitikasi(Request $request)");
    expect(controller).toContain("AuthMiddleware::authenticate($request, true)");
    expect(controller).toContain("self::context($request, 'mevzuat_parametreleri.view')");
    expect(router).toContain("'/sgk-katalog-hazirlik/sirket-politikasi' && $method === 'GET'");
  });

  it("shares the runtime approved selector and excludes drafts", () => {
    expect(runtime).toContain("SgkSirketPolitikaReadService::resolveForPeriod");
    expect(readService).toContain("state = 'ONAYLANDI'");
    expect(readService).not.toContain("state = 'TASLAK'");
    expect(readService).toContain("STATE_NO_APPROVED_POLICY");
    expect(readService).toContain("STATE_CONFLICT");
  });

  it("returns approval and validity metadata without exposing write actions", () => {
    expect(readService).toContain("'approved_policy_id'");
    expect(readService).toContain("'bildirim_donem_tipi'");
    expect(readService).toContain("'gecerlilik_baslangic'");
    expect(readService).toContain("'onaylayan_id'");
    expect(readService).not.toContain("SgkSirketPolitikaWriteService");
  });

  it("preserves fail-closed storage errors at the controller boundary", () => {
    expect(controller).toContain("'SGK_POLITIKA_OKUMA_HATASI'");
    expect(controller).toContain("JsonResponse::error(\n                503");
  });
});
