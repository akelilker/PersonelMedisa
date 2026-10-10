import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

/**
 * 2026-10-10: IK_SORUMLUSU'na özgü 6 IK operasyon izni GENEL_YONETICI'ye de verildi
 * (IK_SORUMLUSU'ndan alınmadı). Dört göz / kendi talebini onaylama yasağı gevşemez.
 */
const root = process.cwd();
const IK_OPERASYON_IZINLERI = [
  "puantaj.donem_reopen.request",
  "puantaj.donem_reseal",
  "puantaj.bildirim_etki.generate",
  "puantaj.bildirim_etki.apply",
  "puantaj.bildirim_etki.dismiss",
  "puantaj.bildirim_etki.resolve_conflict"
] as const;

describe("GENEL_YONETICI IK operasyon izinleri + dört göz", () => {
  it("FE matrisi: 6 izin GY ve IK_SORUMLUSU'nda", () => {
    for (const permission of IK_OPERASYON_IZINLERI) {
      expect(hasRolePermission("GENEL_YONETICI", permission), permission).toBe(true);
      expect(hasRolePermission("IK_SORUMLUSU", permission), permission).toBe(true);
    }
  });

  it("PHP: GY kendi talebini onaylayamaz, onay farklı aktör ister", () => {
    const php = process.platform === "win32"
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const result = spawnSync(php, [resolve(root, "tests/php/GenelYoneticiIkIzinleriDualControlTestRunner.php")], {
      encoding: "utf8",
      cwd: root
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-genel-yonetici-ik-izinleri-dual-control: OK");
    expect(result.stdout).toContain("[PASS] 3 GY kendi talebini onaylayamaz (SELF_APPROVAL_FORBIDDEN)");
    expect(result.stdout).toContain("[PASS] 6 farkli GY aktor onaylayabilir (dort goz)");
  });

  it("kaynak: reopen onayı DualControl ile talep sahibinden ayrışmayı şart koşar", () => {
    const service = readFileSync(resolve(root, "api/src/Services/PuantajDonemReopenService.php"), "utf8");
    const approve = service.slice(service.indexOf("function approveReopenRequest("));
    expect(approve).toContain("DualControl::isSeparated($user, $talep['requested_by'] ?? null, $pdo)");
    expect(approve).toContain("'REOPEN_SELF_APPROVAL_FORBIDDEN'");
    const dual = readFileSync(resolve(root, "api/src/Auth/DualControl.php"), "utf8");
    // Ayrışma kuralı role bakmaz: GY dahil hiçbir rol muaf değildir.
    expect(dual).not.toMatch(/GENEL_YONETICI/);
  });
});
