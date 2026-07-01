import type { UpsertYonetimKullaniciPayload } from "../../types/yonetim";

const REAL_API_UNSUPPORTED_KULLANICI_FIELDS = [
  "telefon",
  "personel_id",
  "notlar",
  "kullanici_tipi"
] as const;

export type RealYonetimKullaniciApiPayload = Omit<
  UpsertYonetimKullaniciPayload,
  (typeof REAL_API_UNSUPPORTED_KULLANICI_FIELDS)[number]
>;

/** Production / real API: unsupported extended kullanici fields are not persisted (V1, no migration). */
export function isRealYonetimKullaniciApi(): boolean {
  const mode = (import.meta.env.VITE_API_MODE ?? "").trim().toLowerCase();
  if (mode === "real") {
    return true;
  }

  const demoFallback = (import.meta.env.VITE_DEMO_API_FALLBACK ?? "true").trim().toLowerCase();
  return demoFallback === "false";
}

export function normalizeSubeIdsWithVarsayilan(subeIds: number[], varsayilanSubeId: number | null | undefined): number[] {
  if (varsayilanSubeId == null || subeIds.length === 0) {
    return subeIds;
  }

  const others = subeIds.filter((id) => id !== varsayilanSubeId);
  return [varsayilanSubeId, ...others];
}

export function sanitizeYonetimKullaniciPayloadForApi(
  payload: UpsertYonetimKullaniciPayload
): UpsertYonetimKullaniciPayload | RealYonetimKullaniciApiPayload {
  if (!isRealYonetimKullaniciApi()) {
    return payload;
  }

  const sanitized: RealYonetimKullaniciApiPayload = {
    username: payload.username,
    password: payload.password,
    ad_soyad: payload.ad_soyad,
    rol: payload.rol,
    sube_ids: payload.sube_ids,
    varsayilan_sube_id: payload.varsayilan_sube_id,
    durum: payload.durum
  };

  return sanitized;
}
