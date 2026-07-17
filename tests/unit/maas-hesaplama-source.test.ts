import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { getRolePermissions } from "../../src/lib/authorization/role-permissions";

const root = process.cwd();

describe("S77-C maas hesaplama source contract", () => {
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

  it("grants view/manage only to muhasebe and genel yonetici", () => {
    expect(getRolePermissions("MUHASEBE")).toContain("maas_hesaplama.view");
    expect(getRolePermissions("MUHASEBE")).toContain("maas_hesaplama.manage");
    expect(getRolePermissions("GENEL_YONETICI")).toContain("maas_hesaplama.manage");
    expect(getRolePermissions("BIRIM_AMIRI")).not.toContain("maas_hesaplama.view");
    expect(getRolePermissions("BOLUM_YONETICISI")).not.toContain("maas_hesaplama.view");
    expect(getRolePermissions("PATRON")).not.toContain("maas_hesaplama.view");
  });
});
