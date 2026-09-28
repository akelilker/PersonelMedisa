import { describe, expect, it } from "vitest";
import { buildCreateSurecPayload, buildUpdateSurecPayload, toSurecFormState } from "../../src/features/surecler/surec-form-utils";
import {
  DEVAMSIZLIK_ALT_TUR_CONFIG,
  DEVAMSIZLIK_SUB_CARDS,
  PUANTAJ_SUBDOMAIN_CARDS
} from "../../src/features/kayit/kayit-surec-constants";
import {
  applyDevamsizlikAltTur,
  resolveDevamsizlikEditSelection
} from "../../src/features/kayit/kayit-surec-utils";
import { INITIAL_SUREC_FORM } from "../../src/hooks/useSurecler";
import type { Surec } from "../../src/types/surec";

const surecTuruOptions = [
  { key: "IZIN", label: "İzin" },
  { key: "DEVAMSIZLIK", label: "Devamsızlık" },
  { key: "RAPOR", label: "Rapor" },
  { key: "IS_KAZASI", label: "İş Kazası" }
];

function existingSurec(surecTuru: string, altTur: string): Surec {
  return {
    id: 40,
    personel_id: 12,
    surec_turu: surecTuru,
    alt_tur: altTur,
    state: "AKTIF",
    baslangic_tarihi: "2026-09-26",
    bitis_tarihi: "2026-09-30",
    ucretli_mi: true,
    tam_gun_mu: null,
    ilk_iki_gun_firma_oder_mi: null,
    aciklama: "Mevcut"
  } as Surec;
}

describe("izin ve devamsizlik presentation", () => {
  it("keeps one combined card and separate rapor / is kazasi cards", () => {
    const titles = PUANTAJ_SUBDOMAIN_CARDS.map((card) => card.title);
    expect(titles.filter((title) => title === "İzin ve Devamsızlık")).toHaveLength(1);
    expect(titles).not.toContain("İzin");
    expect(titles).not.toContain("İzinsiz Gelmedi");
    expect(titles).toContain("Rapor");
    expect(titles).toContain("İş Kazası");
    expect(titles).toContain("Geç Geldi");
    expect(titles).toContain("Erken Çıktı");
    expect(titles).toContain("Görevde");
    expect(DEVAMSIZLIK_SUB_CARDS.map((card) => card.id)).not.toContain("izin-devamsizlik");
  });

  it.each([
    ["YILLIK_IZIN", "IZIN"],
    ["MAZERET_IZNI", "IZIN"],
    ["UCRETSIZ_IZIN", "IZIN"],
    ["IZINSIZ_GELMEDI", "DEVAMSIZLIK"]
  ] as const)("create %s maps to %s", (altTur, surecTuru) => {
    const form = applyDevamsizlikAltTur(
      { ...INITIAL_SUREC_FORM, personelId: "12", baslangicTarihi: "2026-09-26", bitisTarihi: "2026-09-30" },
      "izin_ve_devamsizlik",
      altTur,
      surecTuruOptions
    );
    const payload = buildCreateSurecPayload(form);
    expect(payload.surec_turu).toBe(surecTuru);
    expect(payload.alt_tur).toBe(altTur);
  });

  it.each([
    ["IZIN", "YILLIK_IZIN"],
    ["IZIN", "MAZERET_IZNI"],
    ["IZIN", "UCRETSIZ_IZIN"],
    ["DEVAMSIZLIK", "IZINSIZ_GELMEDI"]
  ] as const)("hydrates %s / %s onto the combined card and keeps domain on save", (surecTuru, altTur) => {
    const selection = resolveDevamsizlikEditSelection(surecTuru, altTur);
    expect(selection?.cardId).toBe("izin_ve_devamsizlik");
    expect(selection?.altTur).toBe(altTur);

    const form = toSurecFormState(existingSurec(surecTuru, altTur));
    const payload = buildUpdateSurecPayload(form);
    expect(payload.surec_turu).toBe(surecTuru);
    expect(payload.alt_tur).toBe(altTur);
  });

  it("keeps rapor and is kazasi edit mapping", () => {
    expect(resolveDevamsizlikEditSelection("RAPOR", "Raporlu_Hastalik")).toMatchObject({
      cardId: "rapor",
      altTur: "Raporlu_Hastalik",
      surecTuru: "RAPOR"
    });
    expect(resolveDevamsizlikEditSelection("IS_KAZASI", "IS_KAZASI_BILDIRIMI")).toMatchObject({
      cardId: "is_kazasi",
      altTur: "IS_KAZASI_BILDIRIMI",
      surecTuru: "IS_KAZASI"
    });
    expect(resolveDevamsizlikEditSelection("DEVAMSIZLIK", "MAZERETSIZ_GEC_GELDI")).toBeNull();
    expect(DEVAMSIZLIK_ALT_TUR_CONFIG.rapor.options.map((option) => option.value)).toEqual([
      "Raporlu_Hastalik",
      "Raporlu_Meslek_Hastaligi",
      "Raporlu_Analik"
    ]);
  });
});
