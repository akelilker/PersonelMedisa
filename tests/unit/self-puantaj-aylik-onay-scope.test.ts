import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

describe("self-service aylik_onayli_mi approval scope", () => {
  it("PHP runner: amir A completion does not approve personel under amir B", () => {
    const runner = resolve(process.cwd(), "tests/php/SelfPuantajAylikOnayScopePhpTestRunner.php");
    const result = spawnSync("php", [runner], { encoding: "utf8", cwd: process.cwd() });
    const combined = `${result.stdout ?? ""}\n${result.stderr ?? ""}`;
    expect(result.status, combined).toBe(0);
    expect(combined).toContain("ALL_PASS self-puantaj-aylik-onay-scope");
  });

  it("MeController passes personel birim_id into scoped monthly approval read", () => {
    const me = readFileSync(resolve(process.cwd(), "api/src/Controllers/MeController.php"), "utf8");
    expect(me).toContain("isAylikOnayli(");
    expect(me).toContain("$birimId");
    expect(me).toContain("birim_id");

    const puantaj = readFileSync(
      resolve(process.cwd(), "api/src/Services/SelfService/SelfPuantajReadService.php"),
      "utf8"
    );
    expect(puantaj).toContain("birim_amiri_user_id = :birim_amiri_user_id");
    expect(puantaj).toContain("resolveBirimAmiriUserIdForPersonelScope");
    expect(puantaj).not.toMatch(
      /WHERE sube_id = :sube_id AND ay = :ay AND state = 'TAMAMLANDI'\s+LIMIT 1/
    );
  });
});
