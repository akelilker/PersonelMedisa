import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { buildCreatePersonelPayload } from "../../src/features/personeller/personel-create-utils";
import type { CreatePersonelFormState } from "../../src/hooks/usePersoneller";

function makeForm(overrides: Partial<CreatePersonelFormState> = {}): CreatePersonelFormState {
  return {
    calisanKapsami: "DIS_KAYNAK",
    tcKimlikNo: "",
    ad: "İlker",
    soyad: "Akel",
    dogumTarihi: "",
    dogumYeri: "",
    kanGrubu: "",
    telefon: "",
    acilDurumKisi: "",
    acilDurumTelefon: "",
    iseGirisTarihi: "2026-01-01",
    subeId: "",
    sgkIsverenId: "",
    calismaLokasyonuId: "",
    departmanId: "",
    bolumId: "",
    birimId: "",
    gorevId: "",
    pozisyonId: "",
    personelTipiId: "",
    bagliAmirId: "",
    ucretTipiId: "",
    maasTutari: "",
    primKuraliId: "",
    ...overrides
  };
}

describe("DIS_KAYNAK minimal personel create", () => {
  it("builds a payload with no organizational assignment and no sicil_no", () => {
    const payload = buildCreatePersonelPayload(makeForm());

    expect(payload.ad).toBe("İlker");
    expect(payload.soyad).toBe("AKEL");
    expect(payload.calisan_kapsami).toBe("DIS_KAYNAK");
    expect(payload.ise_giris_tarihi).toBe("2026-01-01");
    expect(payload.aktif_durum).toBe("AKTIF");
    // Migration 076: no placeholder branch for an unassigned DIS_KAYNAK record.
    expect(payload).not.toHaveProperty("sube_id");
    expect(payload).not.toHaveProperty("departman_id");
    expect(payload).not.toHaveProperty("gorev_id");
    expect(payload).not.toHaveProperty("personel_tipi_id");
    // Auto sicil allocator owns the sicil (migration 078).
    expect(payload).not.toHaveProperty("sicil_no");
    // Unknown personal data is never invented.
    expect(payload.tc_kimlik_no).toBeNull();
    expect(payload.telefon).toBeNull();
    expect(payload.dogum_tarihi).toBeNull();
    expect(payload.sgk_isveren_id).toBeNull();
  });

  it("keeps the organizational fields when they are supplied", () => {
    const payload = buildCreatePersonelPayload(
      makeForm({ subeId: "1", departmanId: "2", gorevId: "3", personelTipiId: "4" })
    );

    expect(payload.sube_id).toBe(1);
    expect(payload.departman_id).toBe(2);
    expect(payload.gorev_id).toBe(3);
    expect(payload.personel_tipi_id).toBe(4);
  });

  it("still requires the organizational fields for IC_PERSONEL", () => {
    expect(() =>
      buildCreatePersonelPayload(
        makeForm({
          calisanKapsami: "IC_PERSONEL",
          tcKimlikNo: "12345678901",
          dogumTarihi: "1990-01-01",
          telefon: "05001112233",
          acilDurumTelefon: "05001112244"
        })
      )
    ).toThrow("Şube seçilmelidir.");
  });

  it("lets the backend accept a branchless create only for unrestricted roles", () => {
    const controller = readFileSync(
      resolve("api/src/Controllers/PersonellerController.php"),
      "utf8"
    );
    expect(controller).toContain("if ($subeId === null || (int) $subeId <= 0)");
    expect(controller).toContain("OrgScope::isUnrestricted($user)");
  });

  it("keeps DIS_KAYNAK organizational fields optional in the create form", () => {
    const fields = readFileSync(
      resolve("src/features/personeller/components/PersonelCreateFields.tsx"),
      "utf8"
    );
    const conditional = fields.match(/required=\{form\.calisanKapsami !== "DIS_KAYNAK"\}/g) ?? [];
    expect(conditional.length).toBeGreaterThanOrEqual(10);
  });
});
