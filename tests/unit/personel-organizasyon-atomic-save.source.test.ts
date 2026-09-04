import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(relativePath: string) {
  return readFileSync(resolve(process.cwd(), relativePath), "utf8");
}

describe("personel organizasyon atomic save closeout", () => {
  it("extends canonical owner with work-info fields in the same transaction", () => {
    const org = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    expect(org).toContain("WORK_INFO_FIELDS");
    expect(org).toContain("'bagli_amir_id'");
    expect(org).toContain("'personel_tipi_id'");
    expect(org).toContain("mutableFields");
    expect(org).toContain("CALISMA_BILGISI_DEGISIKLIGI");
    // Generic PUT still only protects TRACKED_FIELDS
    const guard = org.slice(
      org.indexOf("assertNotChangedViaGenericPut"),
      org.indexOf("public static function apply")
    );
    expect(guard).toContain("TRACKED_FIELDS");
    expect(guard).not.toContain("WORK_INFO_FIELDS");
  });

  it("FE sends one canonical payload and never follows with basic PUT", () => {
    const owner = read("src/features/kayit/kayit-surec-pozisyon.ts");
    expect(owner).toContain("ALL_MUTABLE_FIELDS");
    expect(owner).not.toContain("basic_failed");
    expect(owner).toContain("surecWarning");
    expect(owner).toMatch(/await params\.deps\.applyOrganizasyon/);
    expect(owner).not.toMatch(/await params\.deps\.updatePersonel/);
  });

  it("branch transfer admits IK_SORUMLUSU on backend and frontend", () => {
    const branch = read("api/src/Services/Personel/PersonelKaliciSubeDegisikligiService.php");
    const workspace = read("src/features/kayit/components/KayitSurecWorkspace.tsx");
    expect(branch).toContain("IK_SORUMLUSU");
    expect(branch).toContain(
      "public const ALLOWED_ROLES = ['GENEL_YONETICI', 'SISTEM_YONETICISI', 'IK_SORUMLUSU'];"
    );
    expect(workspace).toContain('actorRole === "IK_SORUMLUSU"');
  });
});
