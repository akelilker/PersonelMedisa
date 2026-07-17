import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { getRolePermissions } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

describe("S77-C/S77-D maas hesaplama source contract", () => {
  it("wires routes, permissions and migrations", () => {
    const router = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
    const permissions = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");
    const migration020 = readFileSync(resolve(root, "api/migrations/020_maas_hesaplama_snapshotlari.sql"), "utf8");
    const migration021 = readFileSync(
      resolve(root, "api/migrations/021_maas_hesaplama_snapshot_guvenlik_indexleri.sql"),
      "utf8"
    );

    expect(router).toContain("maas-hesaplama/preflight");
    expect(router).toContain("maas-hesaplama/snapshotlar");
    expect(permissions).toContain("maas_hesaplama.view");
    expect(permissions).toContain("maas_hesaplama.manage");
    expect(migration020).toContain("maas_hesaplama_donem_snapshotlari");
    expect(migration021).toContain("CREATE TRIGGER");
    expect(migration020.toLowerCase()).not.toContain("drop table");
  });

  it("wires S77-D calculation routes, permissions and additive migrations", () => {
    const router = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
    const permissions = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");
    const migrationFiles = readdirSync(resolve(root, "api/migrations"));

    for (const route of [
      "hesaplama-preflight",
      "hesapla",
      "calistirmalar",
      "adaylar",
      "devirler",
      "yasal-katalog"
    ]) {
      expect(router).toContain(route);
    }
    expect(permissions).toContain("maas_hesaplama_adaylari.view");
    expect(permissions).toContain("maas_hesaplama_adaylari.manage");
    for (const file of [
      "022_personel_bordro_devirleri.sql",
      "023_maas_hesaplama_adaylari.sql",
      "024_maas_hesaplama_aday_guvenlik_indexleri.sql"
    ]) {
      expect(migrationFiles).toContain(file);
      const source = readFileSync(resolve(root, "api/migrations", file), "utf8");
      expect(source).not.toMatch(/\bDROP\s+TABLE\b/i);
    }
  });

  it("grants view/manage only to muhasebe and genel yonetici", () => {
    expect(getRolePermissions("MUHASEBE")).toContain("maas_hesaplama.view");
    expect(getRolePermissions("MUHASEBE")).toContain("maas_hesaplama.manage");
    expect(getRolePermissions("GENEL_YONETICI")).toContain("maas_hesaplama.manage");
    expect(getRolePermissions("BIRIM_AMIRI")).not.toContain("maas_hesaplama.view");
    expect(getRolePermissions("BOLUM_YONETICISI")).not.toContain("maas_hesaplama.view");
    expect(getRolePermissions("PATRON")).not.toContain("maas_hesaplama.view");
  });

  it("grants aday view/manage only to muhasebe and genel yonetici", () => {
    expect(getRolePermissions("MUHASEBE")).toContain("maas_hesaplama_adaylari.view");
    expect(getRolePermissions("MUHASEBE")).toContain("maas_hesaplama_adaylari.manage");
    expect(getRolePermissions("GENEL_YONETICI")).toContain("maas_hesaplama_adaylari.view");
    expect(getRolePermissions("GENEL_YONETICI")).toContain("maas_hesaplama_adaylari.manage");
    expect(getRolePermissions("BIRIM_AMIRI")).not.toContain("maas_hesaplama_adaylari.view");
    expect(getRolePermissions("BOLUM_YONETICISI")).not.toContain("maas_hesaplama_adaylari.view");
    expect(getRolePermissions("PATRON")).not.toContain("maas_hesaplama_adaylari.view");
  });

  it("keeps Money and engine source free of float casts", () => {
    for (const file of [
      "api/src/Services/Money/Money.php",
      "api/src/Services/Payroll/MaasHesaplamaEngine.php"
    ]) {
      const source = readFileSync(resolve(root, file), "utf8");
      expect(source).not.toMatch(/\(\s*float\s*\)/i);
    }
  });
});
