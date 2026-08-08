import type { UpdatePersonelPayload } from "../../api/personeller.api";
import type { Personel } from "../../types/personel";
import type { Surec } from "../../types/surec";
import {
  buildSparsePozisyonUpdatePayload,
  hasPozisyonOrganizationalDiff
} from "./kayit-surec-utils";
import type { PozisyonFormState } from "./kayit-surec-constants";

export type PozisyonWriteDeps = {
  updatePersonel: (personelId: number, payload: UpdatePersonelPayload) => Promise<Personel>;
  createSurec: (payload: {
    personel_id: number;
    surec_turu: "POZISYON_DEGISTI";
    baslangic_tarihi: string;
    aciklama?: string;
  }) => Promise<Surec | unknown>;
};

export type PozisyonWriteResult =
  | { status: "no_op" }
  | { status: "validation_error"; message: string }
  | { status: "update_failed"; error: unknown }
  | { status: "partial_surec_failed"; updated: Personel; error: unknown }
  | { status: "full_success"; updated: Personel };

export function validatePozisyonSubmit(
  form: PozisyonFormState,
  personel: Personel
): { ok: true } | { ok: false; message: string } {
  if (personel.aktif_durum === "PASIF") {
    return { ok: false, message: "Bu personel pasif; pozisyon değişikliği yapılamaz." };
  }
  if (!hasPozisyonOrganizationalDiff(form, personel)) {
    return { ok: false, message: "Pozisyon bilgisi değişmedi." };
  }
  if (!form.effectiveDate) {
    return { ok: false, message: "Değişikliğin geçerli olacağı tarihi seç." };
  }
  if (!form.departmanId || !form.gorevId || !form.personelTipiId) {
    return { ok: false, message: "Bölüm, görev / unvan ve çalışma tipi boş bırakılamaz." };
  }
  return { ok: true };
}

export async function executePozisyonPersonnelUpdate(params: {
  personel: Personel;
  form: PozisyonFormState;
  aciklama: string;
  deps: PozisyonWriteDeps;
}): Promise<PozisyonWriteResult> {
  const validation = validatePozisyonSubmit(params.form, params.personel);
  if (!validation.ok) {
    if (validation.message === "Pozisyon bilgisi değişmedi.") {
      return { status: "no_op" };
    }
    return { status: "validation_error", message: validation.message };
  }

  const payload = buildSparsePozisyonUpdatePayload(params.form, params.personel);
  let updated: Personel;
  try {
    updated = await params.deps.updatePersonel(params.personel.id, payload);
  } catch (error) {
    return { status: "update_failed", error };
  }

  try {
    await params.deps.createSurec({
      personel_id: params.personel.id,
      surec_turu: "POZISYON_DEGISTI",
      baslangic_tarihi: params.form.effectiveDate,
      aciklama: params.aciklama
    });
  } catch (error) {
    return { status: "partial_surec_failed", updated, error };
  }

  return { status: "full_success", updated };
}
