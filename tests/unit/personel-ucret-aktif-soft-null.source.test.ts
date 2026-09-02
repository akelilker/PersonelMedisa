import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

describe("MG personel aktif ucret soft-null source contract", () => {
  it("maps only SALARY_MISSING to HTTP 200 null in PersonelUcretController::aktif", () => {
    const controller = read("api/src/Controllers/PersonelUcretController.php");
    const aktifBlock = controller.slice(
      controller.indexOf("public static function aktif"),
      controller.indexOf("public static function create")
    );

    expect(aktifBlock).toContain("PersonelUcretService::resolveSalaryForDate");
    expect(aktifBlock).toContain("SALARY_MISSING");
    expect(aktifBlock).toContain("JsonResponse::success(null)");
    expect(aktifBlock).toContain("self::error($e)");
    expect(controller).toContain("SALARY_RECORD_NOT_FOUND");
    expect(controller).toContain("SALARY_ACCESS_FORBIDDEN");
  });

  it("keeps frontend null handling for both 200/null and legacy SALARY_MISSING 404", () => {
    const api = read("src/api/ucretler.api.ts");
    expect(api).toContain("response.data === null || response.data === undefined");
    expect(api).toContain('error.code === "SALARY_MISSING" || error.status === 404');
  });

  it("aligns demo and e2e mocks to soft-null aktif responses", () => {
    const demo = read("src/api/mock-demo.ts");
    const e2e = read("tests/e2e/helpers/mock-api.ts");
    expect(demo).toContain("return ok(null);");
    expect(demo).not.toMatch(
      /personelUcretAktifMatch[\s\S]*?return demoRevizyonError\("SALARY_MISSING"/
    );
    expect(e2e).toContain("await fulfillJson(route, 200, okBody(null));");
    expect(e2e).not.toMatch(
      /ucretler\/aktif[\s\S]{0,800}?404, errorBody\("SALARY_MISSING"/
    );
  });
});
