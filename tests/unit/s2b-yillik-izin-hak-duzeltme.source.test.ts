import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, existsSync } from "node:fs";
import { resolve } from "node:path";
import { getRolePermissions, hasRolePermission } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();
const PHP_ROLE_PERMISSIONS_PATH = resolve(root, "api/src/Auth/RolePermissions.php");

function read(rel: string): string {
  return readFileSync(resolve(root, rel), "utf8");
}

function extractPhpRolePermissions(role: string): string[] {
  const php = readFileSync(PHP_ROLE_PERMISSIONS_PATH, "utf8");
  const marker = `'${role}' => [`;
  const start = php.indexOf(marker);
  if (start < 0) {
    return [];
  }

  let index = start + marker.length;
  let depth = 1;
  const permissions: string[] = [];

  while (index < php.length && depth > 0) {
    const char = php[index];
    if (char === "[") {
      depth += 1;
    } else if (char === "]") {
      depth -= 1;
    } else if (depth === 1 && char === "'") {
      let end = index + 1;
      while (end < php.length && php[end] !== "'") {
        end += 1;
      }
      permissions.push(php.slice(index + 1, end));
      index = end;
    }
    index += 1;
  }

  return permissions.sort();
}

describe("S2B yillik izin hak duzeltme (source invariants)", () => {
  it("migration 055 is additive tip; 052/053/054 remain present", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations).toContain("052_puantaj_tolerans_ve_disiplin.sql");
    expect(migrations).toContain("053_retention_legal_hold_arsiv.sql");
    expect(migrations).toContain("054_canonical_role_consolidation.sql");
    expect(migrations).toContain("055_yillik_izin_hak_duzeltmeleri.sql");
    expect(migrations.at(-1)).toBe("057_qr_attendance_events.sql");

    const sql = read("api/migrations/055_yillik_izin_hak_duzeltmeleri.sql");
    expect(sql).toContain("CREATE TABLE IF NOT EXISTS yillik_izin_hak_duzeltmeleri");
    expect(sql).toContain("ENUM('DEVIR', 'EK_HAK', 'DUZELTME', 'TERS_KAYIT')");
    expect(sql).toContain("uq_yihd_reverses_id");
    expect(sql).toContain("chk_yihd_gun_delta_nonzero");
    expect(sql).toContain("chk_yihd_ters_kayit_requires_reverses");
    expect(sql).toContain("aciklama TEXT NOT NULL");
    expect(sql).toContain("created_by INT UNSIGNED NOT NULL");
    expect(sql).not.toContain("INSERT INTO");
  });

  it("grants yillik_izin_hak_duzeltme.manage to GY+IK only in FE and PHP matrices (D1=B)", () => {
    expect(hasRolePermission("GENEL_YONETICI", "yillik_izin_hak_duzeltme.manage")).toBe(true);
    expect(hasRolePermission("IK_SORUMLUSU", "yillik_izin_hak_duzeltme.manage")).toBe(true);
    for (const role of [
      "BOLUM_YONETICISI",
      "MUHASEBE",
      "BIRIM_AMIRI",
      "SISTEM_YONETICISI",
      "PERSONEL"
    ] as const) {
      expect(hasRolePermission(role, "yillik_izin_hak_duzeltme.manage")).toBe(false);
      expect(extractPhpRolePermissions(role)).not.toContain("yillik_izin_hak_duzeltme.manage");
    }
    expect(extractPhpRolePermissions("GENEL_YONETICI")).toContain("yillik_izin_hak_duzeltme.manage");
    expect(extractPhpRolePermissions("IK_SORUMLUSU")).toContain("yillik_izin_hak_duzeltme.manage");
    expect(getRolePermissions("GENEL_YONETICI")).toContain("yillik_izin_hak_duzeltme.manage");
  });

  it("SureclerController create uses RolePermissions::assert (drift fix)", () => {
    const src = read("api/src/Controllers/SureclerController.php");
    expect(src).toContain("RolePermissions::assert($user, 'surecler.create')");
    expect(src).not.toContain("assertCreateRole");
    expect(src).not.toContain("allowedRoles");
    expect(src).not.toMatch(/MUHASEBE.*surecler\.create|\$allowedRoles\s*=\s*\[[^\]]*MUHASEBE/);
  });

  it("Router exposes balance/list/create/ters-kayit without DELETE or kalan_izin overwrite", () => {
    const router = read("api/src/Router.php");
    expect(router).toContain("YillikIzinHakDuzeltmeController");
    expect(router).toContain("yillik-izin-bakiye");
    expect(router).toContain("yillik-izin-hak-duzeltmeleri");
    expect(router).toContain("ters-kayit");
    expect(router).not.toMatch(
      /DELETE.*yillik-izin-hak-duzeltmeleri|yillik-izin-hak-duzeltmeleri.*DELETE/i
    );
    expect(router).not.toContain("kalan_izin");

    const ctrl = read("api/src/Controllers/YillikIzinHakDuzeltmeController.php");
    expect(ctrl).toContain("AuthMiddleware::authenticate");
    expect(ctrl).toContain("yillik_izin_hak_duzeltme.manage");
    expect(ctrl).not.toMatch(/DELETE FROM yillik_izin_hak_duzeltmeleri/);
    expect(ctrl).not.toMatch(/function\s+delete\b/);
  });

  it("PersonelIzinOzetSection uses server bakiye, not local hesaplaIzinBakiye", () => {
    const section = read("src/features/personeller/components/personel-dosya/PersonelIzinOzetSection.tsx");
    expect(section).toContain("fetchYillikIzinBakiye");
    expect(section).toContain("Birikmiş Yasal Hak");
    expect(section).toContain("mevcut_yillik_hak_gun");
    expect(section).toContain("birikmis_yasal_hak_gun");
    expect(section).not.toContain("hesaplaIzinBakiye");
    expect(section).not.toContain("hesaplaIzinHakEdis");
    expect(section).not.toContain("hesaplaBirikmisYasalHak");
  });

  it("KayitSurecWorkspace gates hak duzeltme tile on yillik_izin_hak_duzeltme.manage", () => {
    expect(existsSync(resolve(root, "src/features/kayit/components/YillikIzinHakDuzeltmePanel.tsx"))).toBe(true);

    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(workspace).toContain("YillikIzinHakDuzeltmePanel");
    expect(workspace).toContain('hasPermission("yillik_izin_hak_duzeltme.manage")');
    expect(workspace).toContain('data-testid="yillik-izin-hak-duzeltme-tile"');
    expect(workspace).toContain("canManageYillikIzinHak");
  });

  it("Süreç-only entitlement write: panel not rendered on Kayıt (yeni-kayit) tab", () => {
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    // Panel appears only inside surec branch with selected personel + hakDuzeltmeOpen.
    expect(workspace).toMatch(/activeTab === ["']surec["']/);
    expect(workspace).toContain("hakDuzeltmeOpen && selectedSurecPersonel");
    expect(workspace).toContain("<YillikIzinHakDuzeltmePanel");

    // Kayıt first-create tab block must not mount the entitlement write panel.
    const kayitBlockStart = workspace.indexOf('activeTab === "yeni-kayit"');
    expect(kayitBlockStart).toBeGreaterThan(-1);
    const surecBlockStart = workspace.indexOf('activeTab === "surec"', kayitBlockStart + 1);
    const kayitBlock =
      surecBlockStart > kayitBlockStart
        ? workspace.slice(kayitBlockStart, surecBlockStart)
        : workspace.slice(kayitBlockStart);
    expect(kayitBlock).not.toContain("YillikIzinHakDuzeltmePanel");
    expect(kayitBlock).not.toContain("yillik-izin-hak-duzeltme-tile");
  });

  it("PHP Izin service owners exist under Medisa\\Api\\Services\\Izin", () => {
    for (const file of [
      "api/src/Services/Izin/YillikIzinHakEdisService.php",
      "api/src/Services/Izin/YillikIzinKullanimService.php",
      "api/src/Services/Izin/YillikIzinHakDuzeltmeLedgerService.php",
      "api/src/Services/Izin/YillikIzinBakiyeService.php"
    ]) {
      expect(existsSync(resolve(root, file))).toBe(true);
      expect(read(file)).toContain("namespace Medisa\\Api\\Services\\Izin");
    }

    const bakiye = read("api/src/Services/Izin/YillikIzinBakiyeService.php");
    expect(bakiye).toContain("CUMULATIVE_STATUTORY_ACCRUAL_AS_OF_REFERENCE_DATE");
    expect(bakiye).toContain("CURRENT_SERVICE_YEAR_BAND");
    expect(bakiye).toContain("hesaplaBirikmisYasalHak");
    expect(bakiye).toContain("netSumAsOf");
    expect(bakiye).toContain("resolveReferansTarih");
    expect(bakiye).toContain("max($rawRemaining, 0)");

    const hakEdis = read("api/src/Services/Izin/YillikIzinHakEdisService.php");
    expect(hakEdis).toContain("function hesaplaBirikmisYasalHak");
    expect(hakEdis).toContain("function hesaplaYillikIzinGun");

    const ledger = read("api/src/Services/Izin/YillikIzinHakDuzeltmeLedgerService.php");
    expect(ledger).toContain("function netSumAsOf");
    expect(ledger).toContain("function countByPersonelAsOf");
    expect(ledger).toContain("effective_date <=");

    const kullanim = read("api/src/Services/Izin/YillikIzinKullanimService.php");
    expect(kullanim).toContain("referansTarih");
  });
});
