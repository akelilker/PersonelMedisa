import type { RevizyonCorrectionEvent, RevizyonCorrectionTipi } from "../../types/revizyon-correction";
import type { RevizyonTalebi, RevizyonTipi } from "../../types/revizyon-talebi";

export function mapRevizyonTipiToCorrectionTipi(
  revizyonTipi: RevizyonTipi
): RevizyonCorrectionTipi | null {
  switch (revizyonTipi) {
    case "PUANTAJ_GIRIS_CIKIS_DUZELTME":
      return "GIRIS_CIKIS_DUZELTME";
    case "MOLA_DUZELTME":
      return "MOLA_DUZELTME";
    case "DEVAMSIZLIK_DUZELTME":
      return "DEVAMSIZLIK_DUZELTME";
    case "SERBEST_ZAMAN_ETKI_DUZELTME":
      return "SERBEST_ZAMAN_ETKI_DUZELTME";
    case "KAPANIS_HESAP_REVIZYONU":
      return "KAPANIS_HESAP_REVIZYONU";
    case "BORDRO_ETKI_NOTU":
      return "BORDRO_ETKI_NOTU";
    case "SUREC_GEC_GIRIS":
      return null;
    default:
      return null;
  }
}

export function calculateCorrectionDelta(talep: RevizyonTalebi): {
  delta_dakika: number;
  delta_gun: number;
} {
  const onceki = talep.onceki_deger;
  const yeni = talep.talep_edilen_deger;

  if (typeof onceki === "number" && typeof yeni === "number") {
    return {
      delta_dakika: yeni - onceki,
      delta_gun: 0
    };
  }

  return {
    delta_dakika: 0,
    delta_gun: 0
  };
}

export type BuildCorrectionFromRevizyonTalebiParams = {
  talep: RevizyonTalebi;
  id: number;
  actorUserId: number;
  nowIso: string;
  snapshotRef?: string | null;
  auditRef?: string;
};

export function buildCorrectionFromRevizyonTalebi(
  params: BuildCorrectionFromRevizyonTalebiParams
):
  | RevizyonCorrectionEvent
  | { ok: false; code: "CORRECTION_NOT_ALLOWED_FOR_STATE" | "CORRECTION_TARGET_NOT_FOUND" } {
  const { talep, id, actorUserId, nowIso, snapshotRef, auditRef } = params;

  if (talep.durum !== "ONAYLANDI") {
    return { ok: false, code: "CORRECTION_NOT_ALLOWED_FOR_STATE" };
  }

  const correction_tipi = mapRevizyonTipiToCorrectionTipi(talep.revizyon_tipi);
  if (correction_tipi === null) {
    return { ok: false, code: "CORRECTION_TARGET_NOT_FOUND" };
  }

  const { delta_dakika, delta_gun } = calculateCorrectionDelta(talep);

  return {
    id,
    revizyon_talebi_id: talep.id,
    personel_id: talep.personel_id,
    hafta_baslangic: talep.hafta_baslangic,
    hafta_bitis: talep.hafta_bitis,
    etkilenen_tarih: talep.etkilenen_tarih,
    kaynak_tipi: talep.kaynak_tipi,
    kaynak_id: talep.kaynak_id,
    correction_tipi,
    onceki_deger: talep.onceki_deger,
    yeni_deger: talep.talep_edilen_deger,
    delta_dakika,
    delta_gun,
    bordro_etki_var_mi: talep.bordro_etki_var_mi,
    bordro_etki_tipi: talep.bordro_etki_var_mi ? talep.revizyon_tipi : null,
    aciklama: talep.karar_notu ?? talep.gerekce ?? null,
    olusturan_kullanici_id: actorUserId,
    olusturma_zamani: nowIso,
    iptal_edildi_mi: false,
    iptal_zamani: null,
    iptal_eden_kullanici_id: null,
    audit_ref: auditRef ?? `REV-CORR-${talep.id}-${id}`,
    snapshot_ref: snapshotRef ?? null
  };
}
