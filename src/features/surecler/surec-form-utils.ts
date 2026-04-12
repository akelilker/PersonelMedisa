import type { CreateSurecPayload, UpdateSurecPayload } from "../../api/surecler.api";
import type { SurecFormState } from "../../hooks/useSurecler";
import type { Surec } from "../../types/surec";

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

export function toSurecFormState(surec: Surec): SurecFormState {
  return {
    personelId: String(surec.personel_id),
    surecTuru: surec.surec_turu,
    altTur: surec.alt_tur ?? "",
    baslangicTarihi: surec.baslangic_tarihi ?? "",
    bitisTarihi: surec.bitis_tarihi ?? "",
    ucretliMi: surec.ucretli_mi ?? true,
    aciklama: surec.aciklama ?? ""
  };
}

export function buildCreateSurecPayload(form: SurecFormState): CreateSurecPayload {
  return {
    personel_id: parseRequiredPositiveInt(form.personelId, "Personel ID"),
    surec_turu: form.surecTuru.trim(),
    alt_tur: form.altTur.trim() || undefined,
    baslangic_tarihi: form.baslangicTarihi,
    bitis_tarihi: form.bitisTarihi.trim() || undefined,
    ucretli_mi: form.ucretliMi,
    aciklama: form.aciklama.trim() || undefined
  };
}

export function buildUpdateSurecPayload(form: SurecFormState): UpdateSurecPayload {
  return {
    personel_id: parseRequiredPositiveInt(form.personelId, "Personel ID"),
    surec_turu: form.surecTuru.trim(),
    alt_tur: form.altTur.trim() || undefined,
    baslangic_tarihi: form.baslangicTarihi,
    bitis_tarihi: form.bitisTarihi.trim() || undefined,
    ucretli_mi: form.ucretliMi,
    aciklama: form.aciklama.trim() || undefined
  };
}
