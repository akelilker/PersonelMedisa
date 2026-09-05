import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const serviceSource = readFileSync(
  resolve(root, "api/src/Services/Personel/PersonelIstenAyrilmaService.php"),
  "utf8"
);
const controllerSource = readFileSync(
  resolve(root, "api/src/Controllers/SureclerController.php"),
  "utf8"
);
const workspaceSource = readFileSync(
  resolve(root, "src/features/kayit/components/KayitSurecWorkspace.tsx"),
  "utf8"
);
const roleSource = readFileSync(resolve(root, "api/src/Auth/RolePermissions.php"), "utf8");

describe("Personel ISTEN_AYRILMA operational source contract", () => {
  it("locks canonical write owner and UI PASIF warning", () => {
    expect(serviceSource).toContain("createPersonelLifecycleManifests");
    expect(serviceSource).toContain("EXIT_PREIMAGE_NOT_AKTIF");
    expect(serviceSource).toContain("EXIT_BEFORE_HIRE_DATE");
    expect(serviceSource).toContain("EXIT_ALREADY_ACTIVE");
    expect(serviceSource).toContain("aktif_durum = 'PASIF'");
    expect(controllerSource).toContain("PersonelIstenAyrilmaService::applyInTransaction");
    expect(controllerSource).not.toContain("function deactivatePersonel");
    expect(workspaceSource).toContain("kayit-ayrilma-pasif-uyari");
    expect(workspaceSource).toContain("Ayrılmayı Kaydet");
    expect(workspaceSource).toContain("refetchPersonelDetailAfterIstenAyrilma");
  });

  it("keeps IK_SORUMLUSU surecler.create without opening BIRIM_AMIRI/MUHASEBE/PERSONEL", () => {
    expect(roleSource).toMatch(/'IK_SORUMLUSU' => \[[\s\S]*?'surecler\.create'/);
    const birimBlock = roleSource.slice(
      roleSource.indexOf("'BIRIM_AMIRI' => ["),
      roleSource.indexOf("'IK_SORUMLUSU' => [")
    );
    expect(birimBlock).not.toContain("'surecler.create'");
    const muhasebeBlock = roleSource.slice(
      roleSource.indexOf("'MUHASEBE' => ["),
      roleSource.indexOf("'BIRIM_AMIRI' => [")
    );
    expect(muhasebeBlock).not.toContain("'surecler.create'");
    const personelBlock = roleSource.slice(
      roleSource.indexOf("'PERSONEL' => ["),
      roleSource.indexOf("IK_PERSONELI_WITHHELD")
    );
    expect(personelBlock).not.toContain("'surecler.create'");
  });
});
