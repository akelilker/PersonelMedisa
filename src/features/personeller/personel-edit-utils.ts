import type { UpdatePersonelPayload } from "../../api/personeller.api";
import type { CreateSurecPayload } from "../../api/surecler.api";
import type { LifecycleFormFields } from "../../lib/personel-lifecycle-diff";
import type { Personel } from "../../types/personel";
import type { IdOption } from "../../types/referans";

export type BagliAmirContext = {
  personelId: number;
  departmanId: string;
  subeId: number | null;
};

export type BagliAmirFormGuidance = {
  infoMessage: string | null;
  subeWarning: string | null;
  departmanWarning: string | null;
};

const BAGLI_AMIR_ATANDI_SUREC_TURU = "BAGLI_AMIR_ATANDI";
const BAGLI_AMIR_DEGISTI_SUREC_TURU = "BAGLI_AMIR_DEGISTI";
const BAGLI_AMIR_ATAMASI_KALDIRILDI_SUREC_TURU = "BAGLI_AMIR_ATAMASI_KALDIRILDI";

export type EditPersonelFormState = {
  ad: string;
  soyad: string;
  telefon: string;
  departmanId: string;
  gorevId: string;
  bagliAmirId: string;
  ucretTipiId: string;
  maasTutari: string;
  primKuraliId: string;
  effectiveDate: string;
};

export function buildBagliAmirContext(personel: Personel): BagliAmirContext {
  return {
    personelId: personel.id,
    departmanId: personel.departman_id != null ? String(personel.departman_id) : "",
    subeId: personel.sube_id ?? null
  };
}

export function buildBagliAmirFormGuidance(
  departmanId: string,
  context: BagliAmirContext | null,
  activeSubeId: number | null
): BagliAmirFormGuidance {
  if (!context) {
    return {
      infoMessage: null,
      subeWarning: null,
      departmanWarning: null
    };
  }

  const subeWarning =
    activeSubeId !== null && context.subeId !== null && context.subeId !== activeSubeId
      ? "Secilen Sube, bagli amirin mevcut yetki kapsami ile uyusmuyor."
      : null;

  const departmanWarning =
    context.departmanId && departmanId && context.departmanId !== departmanId
      ? "Secilen Bolum, bagli amirin mevcut yetki kapsami ile uyusmuyor."
      : null;

  const infoMessage =
    context.departmanId &&
    departmanId === context.departmanId &&
    !subeWarning &&
    !departmanWarning
      ? "Sube ve Bolum bilgileri, secilen Birim Amirine gore otomatik olarak guncellendi."
      : null;

  return {
    infoMessage,
    subeWarning,
    departmanWarning
  };
}

export function resolveBagliAmirLabel(
  amirId: number | null,
  options: IdOption[],
  fallback?: string | null
): string {
  if (typeof fallback === "string" && fallback.trim()) {
    return fallback.trim();
  }

  if (typeof amirId !== "number") {
    return "-";
  }

  const option = options.find((item) => item.id === amirId);
  return option?.label ?? `#${amirId}`;
}

export function buildBagliAmirSurecPayloads(
  previousPersonel: Personel,
  updatedPersonel: Personel,
  effectiveDate: string,
  options: IdOption[]
): CreateSurecPayload[] {
  const previousAmirId = previousPersonel.bagli_amir_id ?? null;
  const nextAmirId = updatedPersonel.bagli_amir_id ?? null;

  if (previousAmirId === nextAmirId) {
    return [];
  }

  const previousAmirLabel = resolveBagliAmirLabel(
    previousAmirId,
    options,
    previousPersonel.bagli_amir_adi ?? null
  );
  const nextAmirLabel = resolveBagliAmirLabel(nextAmirId, options, updatedPersonel.bagli_amir_adi ?? null);

  if (previousAmirId === null && nextAmirId !== null) {
    return [
      {
        personel_id: updatedPersonel.id,
        surec_turu: BAGLI_AMIR_ATANDI_SUREC_TURU,
        baslangic_tarihi: effectiveDate,
        aciklama: `${nextAmirLabel} bagli amir olarak atandi.`
      }
    ];
  }

  if (previousAmirId !== null && nextAmirId === null) {
    return [
      {
        personel_id: updatedPersonel.id,
        surec_turu: BAGLI_AMIR_ATAMASI_KALDIRILDI_SUREC_TURU,
        baslangic_tarihi: effectiveDate,
        aciklama: `${previousAmirLabel} icin bagli amir atamasi kaldirildi.`
      }
    ];
  }

  return [
    {
      personel_id: updatedPersonel.id,
      surec_turu: BAGLI_AMIR_DEGISTI_SUREC_TURU,
      baslangic_tarihi: effectiveDate,
      aciklama: `Bagli Amir ${previousAmirLabel} yerine ${nextAmirLabel} olarak guncellendi.`
    }
  ];
}

export function pickLifecycleFormFields(form: EditPersonelFormState): LifecycleFormFields {
  return {
    departmanId: form.departmanId,
    gorevId: form.gorevId,
    bagliAmirId: form.bagliAmirId,
    ucretTipiId: form.ucretTipiId,
    maasTutari: form.maasTutari,
    primKuraliId: form.primKuraliId
  };
}

export function personelToEditForm(personel: Personel): EditPersonelFormState {
  return {
    ad: personel.ad,
    soyad: personel.soyad,
    telefon: personel.telefon ?? "",
    departmanId: personel.departman_id != null ? String(personel.departman_id) : "",
    gorevId: personel.gorev_id != null ? String(personel.gorev_id) : "",
    bagliAmirId: personel.bagli_amir_id != null ? String(personel.bagli_amir_id) : "",
    ucretTipiId: personel.ucret_tipi_id != null ? String(personel.ucret_tipi_id) : "",
    maasTutari: personel.maas_tutari != null ? String(personel.maas_tutari) : "",
    primKuraliId: personel.prim_kurali_id != null ? String(personel.prim_kurali_id) : "",
    effectiveDate: ""
  };
}

export function buildPersonelUpdatePayload(
  editForm: EditPersonelFormState,
  hasLifecycleDiff: boolean
): UpdatePersonelPayload {
  const payload: UpdatePersonelPayload = {
    ad: editForm.ad.trim(),
    soyad: editForm.soyad.trim(),
    telefon: editForm.telefon.trim()
  };

  if (!hasLifecycleDiff) {
    return payload;
  }

  const idPayload = payload as Record<string, number | null | undefined>;

  const setOptionalId = (
    key: "departman_id" | "gorev_id" | "bagli_amir_id" | "prim_kurali_id" | "ucret_tipi_id",
    raw: string
  ) => {
    const trimmed = raw.trim();
    if (trimmed === "") {
      idPayload[key] = null;
      return;
    }

    const parsed = Number.parseInt(trimmed, 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      idPayload[key] = parsed;
    }
  };

  setOptionalId("departman_id", editForm.departmanId);
  setOptionalId("gorev_id", editForm.gorevId);
  setOptionalId("bagli_amir_id", editForm.bagliAmirId);
  setOptionalId("ucret_tipi_id", editForm.ucretTipiId);
  setOptionalId("prim_kurali_id", editForm.primKuraliId);

  const maasRaw = editForm.maasTutari.trim();
  if (maasRaw === "") {
    idPayload.maas_tutari = null;
  } else {
    const parsed = Number.parseFloat(maasRaw.replace(",", "."));
    idPayload.maas_tutari = Number.isFinite(parsed) ? parsed : null;
  }

  payload.effective_date = editForm.effectiveDate.trim();

  return payload;
}

