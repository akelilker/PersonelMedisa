import { describe, expect, it, vi } from "vitest";
import { createOrganizasyonFormFromPersonel } from "../../src/features/kayit/kayit-surec-constants";
import {
  buildBasicWorkInfoUpdatePayload,
  buildOrganizasyonTargets,
  executeKaliciSubeDegisikligi,
  executeOrganizasyonPersonnelUpdate,
  hasOrganizasyonFormDiff,
  hasOrgTrackedDiff
} from "../../src/features/kayit/kayit-surec-pozisyon";
import type { Personel } from "../../src/types/personel";

function makePersonel(overrides: Partial<Personel> = {}): Personel {
  return {
    id: 1,
    tc_kimlik_no: "12345678901",
    ad: "Ayşe",
    soyad: "Yılmaz",
    aktif_durum: "AKTIF",
    sube_id: 1,
    departman_id: 3,
    bolum_id: 1,
    birim_id: 2,
    gorev_id: 1,
    pozisyon_id: 4,
    bagli_amir_id: 9,
    personel_tipi_id: 1,
    sgk_isveren_id: 1,
    calisma_lokasyonu_id: 9,
    departman_adi: "Döşeme",
    gorev_adi: "Genel Müdür",
    bagli_amir_adi: "Demo Amir",
    personel_tipi_adi: "Tam Zamanlı",
    ...overrides
  };
}

describe("canonical organizasyon personnel update", () => {
  it("prefills tracked org + work info fields", () => {
    const form = createOrganizasyonFormFromPersonel(makePersonel());
    expect(form.departmanId).toBe("3");
    expect(form.bolumId).toBe("1");
    expect(form.birimId).toBe("2");
    expect(form.gorevId).toBe("1");
    expect(form.pozisyonId).toBe("4");
    expect(form.bagliAmirId).toBe("9");
    expect(form.personelTipiId).toBe("1");
    expect(form.sgkIsverenId).toBe("1");
    expect(form.calismaLokasyonuId).toBe("9");
  });

  it("no-op produces zero writes", async () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.aciklama = "enough chars for reason";
    const applyOrganizasyon = vi.fn();
    const updatePersonel = vi.fn();
    const createSurec = vi.fn();
    const result = await executeOrganizasyonPersonnelUpdate({
      personel,
      form,
      deps: { applyOrganizasyon, updatePersonel, createSurec }
    });
    expect(result.status).toBe("no_op");
    expect(applyOrganizasyon).not.toHaveBeenCalled();
    expect(updatePersonel).not.toHaveBeenCalled();
    expect(createSurec).not.toHaveBeenCalled();
  });

  it("requires gerekce for tracked org changes", async () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.aciklama = "short";
    const applyOrganizasyon = vi.fn();
    const result = await executeOrganizasyonPersonnelUpdate({
      personel,
      form,
      deps: { applyOrganizasyon, updatePersonel: vi.fn() }
    });
    expect(result.status).toBe("validation_error");
    expect(applyOrganizasyon).not.toHaveBeenCalled();
  });

  it("gorev change uses organizasyon endpoint not generic PUT", async () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.aciklama = "Gorev unvan degisikligi";
    const updated = makePersonel({ gorev_id: 2, gorev_adi: "Üretim Müdürü" });
    const applyOrganizasyon = vi.fn().mockResolvedValue({ personel: updated });
    const updatePersonel = vi.fn();
    const createSurec = vi.fn().mockResolvedValue({ id: 99 });
    const result = await executeOrganizasyonPersonnelUpdate({
      personel,
      form,
      deps: { applyOrganizasyon, updatePersonel, createSurec }
    });
    expect(result.status).toBe("full_success");
    expect(applyOrganizasyon).toHaveBeenCalledTimes(1);
    expect(applyOrganizasyon.mock.calls[0][1].targets).toEqual({ gorev_id: 2 });
    expect(updatePersonel).not.toHaveBeenCalled();
    expect(createSurec).toHaveBeenCalledWith(
      expect.objectContaining({ surec_turu: "POZISYON_DEGISTI", personel_id: 1 })
    );
  });

  it("amir-only change uses basic PUT and skips org endpoint", async () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.bagliAmirId = "10";
    const updated = makePersonel({ bagli_amir_id: 10 });
    const applyOrganizasyon = vi.fn();
    const updatePersonel = vi.fn().mockResolvedValue(updated);
    const result = await executeOrganizasyonPersonnelUpdate({
      personel,
      form,
      deps: { applyOrganizasyon, updatePersonel }
    });
    expect(result.status).toBe("full_success");
    expect(applyOrganizasyon).not.toHaveBeenCalled();
    expect(updatePersonel).toHaveBeenCalledWith(1, { bagli_amir_id: 10 });
  });

  it("org success then basic failure returns basic_failed with updated personel", async () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.bagliAmirId = "10";
    form.aciklama = "Org + amir degisikligi";
    const orgUpdated = makePersonel({ gorev_id: 2 });
    const applyOrganizasyon = vi.fn().mockResolvedValue({ personel: orgUpdated });
    const updatePersonel = vi.fn().mockRejectedValue(new Error("basic boom"));
    const result = await executeOrganizasyonPersonnelUpdate({
      personel,
      form,
      deps: { applyOrganizasyon, updatePersonel }
    });
    expect(result.status).toBe("basic_failed");
    if (result.status === "basic_failed") {
      expect(result.updated.gorev_id).toBe(2);
    }
  });

  it("builds org targets sparse for changed fields only", () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.departmanId = "2";
    form.bolumId = "";
    form.birimId = "";
    const { targets, preimage } = buildOrganizasyonTargets(form, personel);
    expect(preimage.departman_id).toBe(3);
    expect(targets).toEqual({
      departman_id: 2,
      bolum_id: null,
      birim_id: null
    });
    expect(hasOrgTrackedDiff(form, personel)).toBe(true);
    expect(hasOrganizasyonFormDiff(form, personel)).toBe(true);
  });

  it("basic payload never includes tracked org fields", () => {
    const personel = makePersonel();
    const form = createOrganizasyonFormFromPersonel(personel);
    form.bagliAmirId = "11";
    form.personelTipiId = "2";
    form.departmanId = "99";
    expect(buildBasicWorkInfoUpdatePayload(form, personel)).toEqual({
      bagli_amir_id: 11,
      personel_tipi_id: 2
    });
  });

  it("kalici sube transfer validates and calls branch owner", async () => {
    const personel = makePersonel();
    const applyKaliciSube = vi.fn().mockResolvedValue(makePersonel({ sube_id: 2 }));
    const result = await executeKaliciSubeDegisikligi({
      personel,
      yeniSubeId: "2",
      gerekce: "Yeni sube acilisi icin transfer",
      deps: { applyKaliciSube }
    });
    expect(result.status).toBe("full_success");
    expect(applyKaliciSube).toHaveBeenCalledWith(1, {
      beklenen_mevcut_sube_id: 1,
      yeni_sube_id: 2,
      gerekce: "Yeni sube acilisi icin transfer"
    });
  });

  it("same-branch transfer is denied", async () => {
    const result = await executeKaliciSubeDegisikligi({
      personel: makePersonel(),
      yeniSubeId: "1",
      gerekce: "Yeni sube acilisi icin transfer",
      deps: { applyKaliciSube: vi.fn() }
    });
    expect(result.status).toBe("validation_error");
  });
});
