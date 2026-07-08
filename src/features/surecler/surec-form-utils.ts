import type { CreateSurecPayload, UpdateSurecPayload } from "../../api/surecler.api";
import type { SurecFormState } from "../../hooks/useSurecler";
import type { Surec } from "../../types/surec";

export function isHastalikRaporSureci(surecTuru: string, altTur: string) {
  return surecTuru.trim() === "RAPOR" && altTur.trim() === "Raporlu_Hastalik";
}

export function parsePositiveInt(value: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    return undefined;
  }
  return number;
}

export function parseRequiredPositiveInt(value: string, label: string) {
  const number = parsePositiveInt(value);
  if (!number) {
    throw new Error(`${label} pozitif sayı olmalıdır.`);
  }
  return number;
}

function parseRequiredSurecTuru(value: string) {
  const surecTuru = value.trim();
  if (!surecTuru) {
    throw new Error("Surec turu zorunludur.");
  }
  return surecTuru;
}

export function toSurecFormState(surec: Surec): SurecFormState {
  return {
    personelId: String(surec.personel_id),
    surecTuru: surec.surec_turu,
    altTur: surec.alt_tur ?? "",
    baslangicTarihi: surec.baslangic_tarihi ?? "",
    bitisTarihi: surec.bitis_tarihi ?? "",
    ucretliMi: surec.ucretli_mi ?? true,
    ilkIkiGunFirmaOderMi: surec.ilk_iki_gun_firma_oder_mi ?? false,
    aciklama: surec.aciklama ?? ""
  };
}

function appendIlkIkiGunFirmaOderMiPayload<T extends CreateSurecPayload | UpdateSurecPayload>(
  payload: T,
  form: SurecFormState
): T {
  if (!isHastalikRaporSureci(form.surecTuru, form.altTur)) {
    return payload;
  }

  return {
    ...payload,
    ilk_iki_gun_firma_oder_mi: form.ilkIkiGunFirmaOderMi
  };
}

export function buildCreateSurecPayload(form: SurecFormState): CreateSurecPayload {
  return appendIlkIkiGunFirmaOderMiPayload(
    {
      personel_id: parseRequiredPositiveInt(form.personelId, "Personel ID"),
      surec_turu: parseRequiredSurecTuru(form.surecTuru),
      alt_tur: form.altTur.trim() || undefined,
      baslangic_tarihi: form.baslangicTarihi,
      bitis_tarihi: form.bitisTarihi.trim() || undefined,
      ucretli_mi: form.ucretliMi,
      aciklama: form.aciklama.trim() || undefined
    },
    form
  );
}

export function buildUpdateSurecPayload(form: SurecFormState): UpdateSurecPayload {
  return appendIlkIkiGunFirmaOderMiPayload(
    {
      personel_id: parseRequiredPositiveInt(form.personelId, "Personel ID"),
      surec_turu: parseRequiredSurecTuru(form.surecTuru),
      alt_tur: form.altTur.trim() || undefined,
      baslangic_tarihi: form.baslangicTarihi,
      bitis_tarihi: form.bitisTarihi.trim() || undefined,
      ucretli_mi: form.ucretliMi,
      aciklama: form.aciklama.trim() || undefined
    },
    form
  );
}
