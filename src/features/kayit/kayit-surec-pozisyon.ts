import type {
  ApplyKaliciSubeDegisikligiPayload,
  ApplyOrganizasyonDegisikligiPayload,
  OrganizasyonFieldMap,
  OrganizasyonMutableField,
  OrganizasyonTrackedField
} from "../../api/personeller.api";
import type { Personel } from "../../types/personel";
import type { Surec } from "../../types/surec";
import { parsePozisyonId, toOptionalIdValue } from "./kayit-surec-utils";
import type { OrganizasyonFormState } from "./kayit-surec-constants";

const ORG_TRACKED_FIELDS: OrganizasyonTrackedField[] = [
  "departman_id",
  "bolum_id",
  "birim_id",
  "gorev_id",
  "pozisyon_id",
  "sgk_isveren_id",
  "calisma_lokasyonu_id"
];

const WORK_INFO_FIELDS: Array<"bagli_amir_id" | "personel_tipi_id"> = [
  "bagli_amir_id",
  "personel_tipi_id"
];

const ALL_MUTABLE_FIELDS: OrganizasyonMutableField[] = [...ORG_TRACKED_FIELDS, ...WORK_INFO_FIELDS];

const GEREKCE_MIN = 10;

export type OrganizasyonWriteDeps = {
  applyOrganizasyon: (
    personelId: number,
    payload: ApplyOrganizasyonDegisikligiPayload
  ) => Promise<{ personel: Personel }>;
  /** @deprecated Basic PUT is no longer used for Görev/Organizasyon save. */
  updatePersonel?: (personelId: number, payload: Record<string, unknown>) => Promise<Personel>;
  createSurec?: (payload: {
    personel_id: number;
    surec_turu: "POZISYON_DEGISTI";
    baslangic_tarihi: string;
    aciklama?: string;
  }) => Promise<Surec | unknown>;
};

export type KaliciSubeWriteDeps = {
  applyKaliciSube: (personelId: number, payload: ApplyKaliciSubeDegisikligiPayload) => Promise<Personel>;
};

export type OrganizasyonWriteResult =
  | { status: "no_op" }
  | { status: "validation_error"; message: string }
  | { status: "org_failed"; error: unknown }
  | { status: "full_success"; updated: Personel; surecWarning?: string };

export type KaliciSubeWriteResult =
  | { status: "validation_error"; message: string }
  | { status: "failed"; error: unknown }
  | { status: "full_success"; updated: Personel };

function formIdToNullable(value: string): number | null {
  return parsePozisyonId(value);
}

function personelOrgSnapshot(personel: Personel): Record<OrganizasyonMutableField, number | null> {
  return {
    departman_id: personel.departman_id ?? null,
    bolum_id: personel.bolum_id ?? null,
    birim_id: personel.birim_id ?? null,
    gorev_id: personel.gorev_id ?? null,
    pozisyon_id: personel.pozisyon_id ?? null,
    sgk_isveren_id: personel.sgk_isveren_id ?? null,
    calisma_lokasyonu_id: personel.calisma_lokasyonu_id ?? null,
    bagli_amir_id: personel.bagli_amir_id ?? null,
    personel_tipi_id: personel.personel_tipi_id ?? null
  };
}

function formOrgSnapshot(form: OrganizasyonFormState): Record<OrganizasyonMutableField, number | null> {
  return {
    departman_id: formIdToNullable(form.departmanId),
    bolum_id: formIdToNullable(form.bolumId),
    birim_id: formIdToNullable(form.birimId),
    gorev_id: formIdToNullable(form.gorevId),
    pozisyon_id: formIdToNullable(form.pozisyonId),
    sgk_isveren_id: formIdToNullable(form.sgkIsverenId),
    calisma_lokasyonu_id: formIdToNullable(form.calismaLokasyonuId),
    bagli_amir_id: formIdToNullable(form.bagliAmirId),
    personel_tipi_id: formIdToNullable(form.personelTipiId)
  };
}

export function hasOrgTrackedDiff(form: OrganizasyonFormState, personel: Personel): boolean {
  const current = personelOrgSnapshot(personel);
  const next = formOrgSnapshot(form);
  return ORG_TRACKED_FIELDS.some((field) => current[field] !== next[field]);
}

export function hasBasicWorkInfoDiff(form: OrganizasyonFormState, personel: Personel): boolean {
  return (
    form.bagliAmirId !== toOptionalIdValue(personel.bagli_amir_id) ||
    form.personelTipiId !== toOptionalIdValue(personel.personel_tipi_id)
  );
}

/** Any editable axis on the Görev / Organizasyon form (org tracked + amir + tip). */
export function hasOrganizasyonFormDiff(form: OrganizasyonFormState, personel: Personel): boolean {
  return hasOrgTrackedDiff(form, personel) || hasBasicWorkInfoDiff(form, personel);
}

/**
 * Sparse targets for every mutable axis (tracked org + work info) in one payload.
 * Single canonical POST keeps the save atomic — no follow-up basic PUT.
 */
export function buildOrganizasyonTargets(
  form: OrganizasyonFormState,
  personel: Personel
): { preimage: OrganizasyonFieldMap; targets: OrganizasyonFieldMap } {
  const current = personelOrgSnapshot(personel);
  const next = formOrgSnapshot(form);
  const preimage: OrganizasyonFieldMap = {};
  const targets: OrganizasyonFieldMap = {};

  for (const field of ALL_MUTABLE_FIELDS) {
    preimage[field] = current[field];
    if (current[field] !== next[field]) {
      targets[field] = next[field];
    }
  }

  return { preimage, targets };
}

/** @deprecated Work-info axes travel with the canonical org owner; kept for tests. */
export function buildBasicWorkInfoUpdatePayload(
  form: OrganizasyonFormState,
  personel: Personel
): { bagli_amir_id?: number | null; personel_tipi_id?: number } {
  const payload: { bagli_amir_id?: number | null; personel_tipi_id?: number } = {};

  if (form.bagliAmirId !== toOptionalIdValue(personel.bagli_amir_id)) {
    payload.bagli_amir_id = formIdToNullable(form.bagliAmirId);
  }
  if (form.personelTipiId !== toOptionalIdValue(personel.personel_tipi_id)) {
    const tip = formIdToNullable(form.personelTipiId);
    if (tip != null) {
      payload.personel_tipi_id = tip;
    }
  }

  return payload;
}

function resolveGerekce(form: OrganizasyonFormState): string {
  const reasonPrefix =
    form.degisiklikNedeni === "TERFI"
      ? "Terfi"
      : form.degisiklikNedeni === "GOREV_DEGISTI"
        ? "Gorev/organizasyon degisikligi"
        : form.degisiklikNedeni === "YAPILANDIRMA"
          ? "Organizasyon yapilandirmasi"
          : "";
  const note = form.aciklama.trim();
  return [reasonPrefix, note].filter(Boolean).join(" | ").trim();
}

export function validateOrganizasyonSubmit(
  form: OrganizasyonFormState,
  personel: Personel
): { ok: true } | { ok: false; message: string } {
  if (personel.aktif_durum === "PASIF") {
    return { ok: false, message: "Bu personel pasif; organizasyon değişikliği yapılamaz." };
  }
  if (!hasOrganizasyonFormDiff(form, personel)) {
    return { ok: false, message: "Organizasyon bilgisi değişmedi." };
  }

  const orgDiff = hasOrgTrackedDiff(form, personel);
  const basicDiff = hasBasicWorkInfoDiff(form, personel);
  if (orgDiff) {
    const gerekce = resolveGerekce(form);
    if (gerekce.length < GEREKCE_MIN) {
      return {
        ok: false,
        message: `Değişiklik nedeni en az ${GEREKCE_MIN} karakter olmalıdır.`
      };
    }
  }

  if (basicDiff && !form.personelTipiId) {
    const isDisKaynak = personel.calisan_kapsami === "DIS_KAYNAK";
    const hadStatu =
      typeof personel.personel_tipi_id === "number" &&
      Number.isFinite(personel.personel_tipi_id) &&
      personel.personel_tipi_id > 0;
    // Harici Personelde boş Statü domain olarak izinlidir; mevcut Statü temizlenemez.
    if (!isDisKaynak || hadStatu) {
      return { ok: false, message: "Statü boş bırakılamaz." };
    }
  }

  if (form.bolumId && !form.departmanId) {
    return { ok: false, message: "Bölüm seçmek için önce departman seçilmelidir." };
  }
  if (form.birimId && !form.bolumId) {
    return { ok: false, message: "Birim seçmek için önce bölüm seçilmelidir." };
  }

  return { ok: true };
}

/**
 * Single canonical mutation for Görev/Organizasyon.
 * Org tracked + bagli_amir + personel_tipi travel together — no partial persist.
 * POZISYON_DEGISTI surec note is best-effort after SUCCESS (never masks a failed save).
 */
export async function executeOrganizasyonPersonnelUpdate(params: {
  personel: Personel;
  form: OrganizasyonFormState;
  deps: OrganizasyonWriteDeps;
}): Promise<OrganizasyonWriteResult> {
  const validation = validateOrganizasyonSubmit(params.form, params.personel);
  if (!validation.ok) {
    if (validation.message === "Organizasyon bilgisi değişmedi.") {
      return { status: "no_op" };
    }
    return { status: "validation_error", message: validation.message };
  }

  const { preimage, targets } = buildOrganizasyonTargets(params.form, params.personel);
  if (Object.keys(targets).length === 0) {
    return { status: "no_op" };
  }

  let updated: Personel;
  try {
    const result = await params.deps.applyOrganizasyon(params.personel.id, {
      preimage,
      targets,
      gerekce: resolveGerekce(params.form)
    });
    updated = result.personel;
  } catch (error) {
    return { status: "org_failed", error };
  }

  let surecWarning: string | undefined;
  if (params.deps.createSurec) {
    const today = new Date().toISOString().slice(0, 10);
    const baslangic = params.form.degisiklikTarihi.trim() || today;
    try {
      await params.deps.createSurec({
        personel_id: params.personel.id,
        surec_turu: "POZISYON_DEGISTI",
        baslangic_tarihi: baslangic > today ? today : baslangic,
        aciklama: resolveGerekce(params.form)
      });
    } catch {
      surecWarning = "Organizasyon kaydedildi; süreç geçmişi notu oluşturulamadı.";
    }
  }

  return { status: "full_success", updated, surecWarning };
}

/** @deprecated Prefer executeOrganizasyonPersonnelUpdate */
export async function executePozisyonPersonnelUpdate(params: {
  personel: Personel;
  form: OrganizasyonFormState;
  aciklama: string;
  deps: {
    updatePersonel?: OrganizasyonWriteDeps["updatePersonel"];
    createSurec: NonNullable<OrganizasyonWriteDeps["createSurec"]>;
    applyOrganizasyon?: OrganizasyonWriteDeps["applyOrganizasyon"];
  };
}): Promise<OrganizasyonWriteResult> {
  if (!params.deps.applyOrganizasyon) {
    return {
      status: "validation_error",
      message: "Organizasyon değişikliği canonical owner gerektirir."
    };
  }

  return executeOrganizasyonPersonnelUpdate({
    personel: params.personel,
    form: { ...params.form, aciklama: params.aciklama || params.form.aciklama },
    deps: {
      applyOrganizasyon: params.deps.applyOrganizasyon,
      createSurec: params.deps.createSurec
    }
  });
}

export function validateKaliciSubeSubmit(params: {
  personel: Personel;
  yeniSubeId: string;
  gerekce: string;
}): { ok: true; payload: ApplyKaliciSubeDegisikligiPayload } | { ok: false; message: string } {
  if (params.personel.aktif_durum === "PASIF") {
    return { ok: false, message: "Bu personel pasif; şube değişikliği yapılamaz." };
  }

  const yeniSubeId = formIdToNullable(params.yeniSubeId);
  if (yeniSubeId == null) {
    return { ok: false, message: "Yeni şube seçilmelidir." };
  }

  const currentSubeId = params.personel.sube_id ?? null;
  if (currentSubeId === yeniSubeId) {
    return { ok: false, message: "Hedef şube mevcut şube ile aynı." };
  }

  const gerekce = params.gerekce.trim();
  if (gerekce.length < GEREKCE_MIN) {
    return { ok: false, message: `Gerekçe en az ${GEREKCE_MIN} karakter olmalıdır.` };
  }
  if (gerekce.length > 500) {
    return { ok: false, message: "Gerekçe en fazla 500 karakter olabilir." };
  }

  return {
    ok: true,
    payload: {
      beklenen_mevcut_sube_id: currentSubeId,
      yeni_sube_id: yeniSubeId,
      gerekce
    }
  };
}

export async function executeKaliciSubeDegisikligi(params: {
  personel: Personel;
  yeniSubeId: string;
  gerekce: string;
  deps: KaliciSubeWriteDeps;
}): Promise<KaliciSubeWriteResult> {
  const validation = validateKaliciSubeSubmit({
    personel: params.personel,
    yeniSubeId: params.yeniSubeId,
    gerekce: params.gerekce
  });
  if (!validation.ok) {
    return { status: "validation_error", message: validation.message };
  }

  try {
    const updated = await params.deps.applyKaliciSube(params.personel.id, validation.payload);
    return { status: "full_success", updated };
  } catch (error) {
    return { status: "failed", error };
  }
}
