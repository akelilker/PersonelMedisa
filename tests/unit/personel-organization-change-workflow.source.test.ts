import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

describe("personel organization change workflow closeout", () => {
  it("FE wires canonical org and branch endpoints", () => {
    const api = read("src/api/personeller.api.ts");
    const endpoints = read("src/api/endpoints.ts");
    expect(endpoints).toContain("organizasyonDegisikligi");
    expect(endpoints).toContain("kaliciSubeDegisikligi");
    expect(api).toContain("applyPersonelOrganizasyonDegisikligi");
    expect(api).toContain("applyPersonelKaliciSubeDegisikligi");
  });

  it("Pozisyon tab orchestrates single atomic canonical org save", () => {
    const owner = read("src/features/kayit/kayit-surec-pozisyon.ts");
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    const orgService = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    expect(owner).toContain("executeOrganizasyonPersonnelUpdate");
    expect(owner).toContain("applyOrganizasyon");
    expect(owner).toContain("bagli_amir_id");
    expect(owner).toContain("personel_tipi_id");
    expect(owner).not.toContain("basic_failed");
    expect(owner).toContain("surecWarning");
    expect(owner).toContain("executeKaliciSubeDegisikligi");
    expect(workspace).toContain("applyPersonelOrganizasyonDegisikligi");
    expect(workspace).toContain("applyPersonelKaliciSubeDegisikligi");
    expect(workspace).toContain("KayitSurecPersonelOrganizasyonPanel");
    expect(workspace).toContain('actorRole === "IK_SORUMLUSU"');
    expect(workspace).not.toMatch(/executePozisyonPersonnelUpdate\s*\(/);
    expect(workspace).not.toContain("basic_failed");
    expect(orgService).toContain("WORK_INFO_FIELDS");
    expect(orgService).toContain("'bagli_amir_id'");
    expect(orgService).toContain("'personel_tipi_id'");
  });

  it("Genel / identity PUT no longer sends tracked org fields", () => {
    const editUtils = read("src/features/personeller/personel-edit-utils.ts");
    const genel = read("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");
    const inline = read("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx");
    expect(editUtils).toContain("Never send TRACKED org fields");
    expect(editUtils).not.toMatch(/setOptionalId\("departman_id"/);
    expect(editUtils).not.toMatch(/setOptionalId\("gorev_id"/);
    expect(genel).toContain("includeBagliAmir: false");
    expect(genel).not.toContain("includeOrgStructureFields");
    expect(inline).toContain("personel-edit-org-yonlendirme");
    expect(inline).not.toContain('name="edit-departman"');
    expect(inline).not.toContain('name="edit-gorev"');
  });

  it("backend referans exposes calisma lokasyonlari", () => {
    const router = read("api/src/Router.php");
    const referans = read("api/src/Controllers/ReferansController.php");
    expect(router).toContain("/referans/calisma-lokasyonlari");
    expect(referans).toContain("function calismaLokasyonlari");
  });
});
