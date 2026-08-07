import { describe, expect, it, vi } from "vitest";
import { createPozisyonFormFromPersonel } from "../../src/features/kayit/kayit-surec-constants";
import { executePozisyonPersonnelUpdate } from "../../src/features/kayit/kayit-surec-pozisyon";
import {
  buildSparsePozisyonUpdatePayload,
  hasPozisyonOrganizationalDiff
} from "../../src/features/kayit/kayit-surec-utils";
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
    gorev_id: 1,
    bagli_amir_id: 9,
    personel_tipi_id: 1,
    departman_adi: "Döşeme",
    gorev_adi: "Genel Müdür",
    bagli_amir_adi: "Demo Amir",
    personel_tipi_adi: "Tam Zamanlı",
    ...overrides
  };
}

describe("I6 surec pozisyon personnel update parity", () => {
  it("prefills departman, gorev, bagli amir and personel tipi from current personel", () => {
    const form = createPozisyonFormFromPersonel(makePersonel());
    expect(form.departmanId).toBe("3");
    expect(form.gorevId).toBe("1");
    expect(form.bagliAmirId).toBe("9");
    expect(form.personelTipiId).toBe("1");
    expect(form.effectiveDate).toBe("");
    expect(form.aciklama).toBe("");
  });

  it("resets optional ids to empty when personel values are missing", () => {
    const form = createPozisyonFormFromPersonel(
      makePersonel({ bagli_amir_id: undefined, bagli_amir_adi: undefined })
    );
    expect(form.bagliAmirId).toBe("");
  });

  it("detects no-op when organizational fields match current personel", () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.effectiveDate = "2026-08-01";
    expect(hasPozisyonOrganizationalDiff(form, personel)).toBe(false);
  });

  it("requires effective date before write", async () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.gorevId = "2";
    const updatePersonel = vi.fn();
    const createSurec = vi.fn();
    const result = await executePozisyonPersonnelUpdate({
      personel,
      form,
      aciklama: "",
      deps: { updatePersonel, createSurec }
    });
    expect(result.status).toBe("validation_error");
    expect(updatePersonel).not.toHaveBeenCalled();
    expect(createSurec).not.toHaveBeenCalled();
  });

  it("no-op produces zero writes", async () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.effectiveDate = "2026-08-01";
    const updatePersonel = vi.fn();
    const createSurec = vi.fn();
    const result = await executePozisyonPersonnelUpdate({
      personel,
      form,
      aciklama: "",
      deps: { updatePersonel, createSurec }
    });
    expect(result.status).toBe("no_op");
    expect(updatePersonel).not.toHaveBeenCalled();
    expect(createSurec).not.toHaveBeenCalled();
  });

  it("builds sparse payload for only departman change", () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.departmanId = "2";
    form.effectiveDate = "2026-08-01";
    expect(buildSparsePozisyonUpdatePayload(form, personel)).toEqual({
      departman_id: 2,
      effective_date: "2026-08-01"
    });
  });

  it("builds sparse payload for only gorev change", () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.effectiveDate = "2026-08-01";
    expect(buildSparsePozisyonUpdatePayload(form, personel)).toEqual({
      gorev_id: 2,
      effective_date: "2026-08-01"
    });
  });

  it("builds sparse payload for only bagli amir change including clear-to-null", () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.bagliAmirId = "10";
    form.effectiveDate = "2026-08-01";
    expect(buildSparsePozisyonUpdatePayload(form, personel)).toEqual({
      bagli_amir_id: 10,
      effective_date: "2026-08-01"
    });

    form.bagliAmirId = "";
    expect(buildSparsePozisyonUpdatePayload(form, personel)).toEqual({
      bagli_amir_id: null,
      effective_date: "2026-08-01"
    });
  });

  it("builds sparse payload for only personel tipi change", () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.personelTipiId = "2";
    form.effectiveDate = "2026-08-01";
    expect(buildSparsePozisyonUpdatePayload(form, personel)).toEqual({
      personel_tipi_id: 2,
      effective_date: "2026-08-01"
    });
  });

  it("builds single sparse payload for multi-field change", () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.departmanId = "2";
    form.gorevId = "2";
    form.bagliAmirId = "10";
    form.effectiveDate = "2026-08-02";
    expect(buildSparsePozisyonUpdatePayload(form, personel)).toEqual({
      departman_id: 2,
      gorev_id: 2,
      bagli_amir_id: 10,
      effective_date: "2026-08-02"
    });
  });

  it("person switch prefill replaces prior dirty values", () => {
    const personA = makePersonel({ id: 1, departman_id: 3, gorev_id: 1 });
    const personB = makePersonel({
      id: 2,
      ad: "Mehmet",
      soyad: "Kaya",
      departman_id: 6,
      gorev_id: 2,
      bagli_amir_id: 10,
      personel_tipi_id: 2
    });

    const dirtyA = createPozisyonFormFromPersonel(personA);
    dirtyA.departmanId = "1";
    dirtyA.gorevId = "3";
    dirtyA.effectiveDate = "2026-08-01";

    const prefilledB = createPozisyonFormFromPersonel(personB);
    expect(prefilledB.departmanId).toBe("6");
    expect(prefilledB.gorevId).toBe("2");
    expect(prefilledB.bagliAmirId).toBe("10");
    expect(prefilledB.personelTipiId).toBe("2");
    expect(prefilledB.effectiveDate).toBe("");
    expect(prefilledB.departmanId).not.toBe(dirtyA.departmanId);
  });

  it("updatePersonel failure does not create surec", async () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.effectiveDate = "2026-08-01";
    const updatePersonel = vi.fn().mockRejectedValue(new Error("update boom"));
    const createSurec = vi.fn();
    const result = await executePozisyonPersonnelUpdate({
      personel,
      form,
      aciklama: "Görev / Unvan: Genel Müdür -> Üretim Müdürü",
      deps: { updatePersonel, createSurec }
    });
    expect(result.status).toBe("update_failed");
    expect(updatePersonel).toHaveBeenCalledTimes(1);
    expect(createSurec).not.toHaveBeenCalled();
  });

  it("createSurec failure returns partial status without full success", async () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.effectiveDate = "2026-08-01";
    const updated = makePersonel({ gorev_id: 2, gorev_adi: "Üretim Müdürü" });
    const updatePersonel = vi.fn().mockResolvedValue(updated);
    const createSurec = vi.fn().mockRejectedValue(new Error("surec boom"));
    const result = await executePozisyonPersonnelUpdate({
      personel,
      form,
      aciklama: "Görev / Unvan: Genel Müdür -> Üretim Müdürü",
      deps: { updatePersonel, createSurec }
    });
    expect(result.status).toBe("partial_surec_failed");
    if (result.status === "partial_surec_failed") {
      expect(result.updated.gorev_id).toBe(2);
    }
    expect(updatePersonel).toHaveBeenCalledTimes(1);
    expect(createSurec).toHaveBeenCalledTimes(1);
  });

  it("successful single-field change issues one update and one POZISYON_DEGISTI", async () => {
    const personel = makePersonel();
    const form = createPozisyonFormFromPersonel(personel);
    form.gorevId = "2";
    form.effectiveDate = "2026-08-01";
    const updated = makePersonel({ gorev_id: 2, gorev_adi: "Üretim Müdürü" });
    const updatePersonel = vi.fn().mockResolvedValue(updated);
    const createSurec = vi.fn().mockResolvedValue({ id: 99 });
    const result = await executePozisyonPersonnelUpdate({
      personel,
      form,
      aciklama: "Görev / Unvan: Genel Müdür -> Üretim Müdürü",
      deps: { updatePersonel, createSurec }
    });
    expect(result.status).toBe("full_success");
    expect(updatePersonel).toHaveBeenCalledTimes(1);
    expect(updatePersonel).toHaveBeenCalledWith(1, {
      gorev_id: 2,
      effective_date: "2026-08-01"
    });
    expect(createSurec).toHaveBeenCalledTimes(1);
    expect(createSurec).toHaveBeenCalledWith({
      personel_id: 1,
      surec_turu: "POZISYON_DEGISTI",
      baslangic_tarihi: "2026-08-01",
      aciklama: "Görev / Unvan: Genel Müdür -> Üretim Müdürü"
    });
  });
});
