import type { ApiResponse } from "../types/api";
import type {
  AylikBolumOnayDurumu,
  AylikOzetAggregateState,
  AylikOzetFilters,
  AylikOzetResponse,
  AylikOzetRow,
  AylikOzetSummary,
  KayitDurumu,
  KullaniciTipi,
  PersonelHesapFirstLoginResult,
  PersonelHesapOnboardingUser,
  OrganizasyonReadiness,
  UpsertYonetimKullaniciPayload,
  UpsertYonetimSgkIsverenPayload,
  UpsertYonetimSirketPayload,
  UpsertYonetimSubePayload,
  YonetimActorIdentityRead,
  YonetimKullanici,
  YonetimOrgRelation,
  YonetimSgkIsveren,
  YonetimSirket,
  YonetimSube
} from "../types/yonetim";
import type { UserRole } from "../types/auth";
import { canonicalizeUserRole } from "../lib/authorization/canonicalize-user-role";
import { SUBE_DELETE_BLOCKED_ERROR_CODE } from "../lib/yonetim/sube-delete";
import { sanitizeYonetimKullaniciPayloadForApi } from "../lib/yonetim/kullanici-api-contract";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function readString(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : undefined;
}

function readStringOrNull(value: unknown): string | null {
  if (value === null) {
    return null;
  }

  return readString(value) ?? null;
}

function readNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return undefined;
}

function readBoolean(value: unknown): boolean {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    return normalized === "true" || normalized === "1" || normalized === "evet";
  }

  return false;
}

function readOptionalBoolean(value: unknown): boolean | undefined {
  if (value === undefined || value === null || value === "") {
    return undefined;
  }

  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "number") {
    if (value === 1) {
      return true;
    }
    if (value === 0) {
      return false;
    }
    return undefined;
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    if (normalized === "true" || normalized === "1") {
      return true;
    }
    if (normalized === "false" || normalized === "0") {
      return false;
    }
  }

  return undefined;
}

function normalizeKayitDurumu(value: unknown): KayitDurumu {
  return value === "PASIF" ? "PASIF" : "AKTIF";
}

function normalizeKullaniciTipi(value: unknown): KullaniciTipi {
  return value === "HARICI" ? "HARICI" : "IC_PERSONEL";
}

function normalizeUserRole(value: unknown): UserRole {
  return canonicalizeUserRole(value) ?? "BIRIM_AMIRI";
}

function readNumberArray(value: unknown): number[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((item) => readNumber(item))
    .filter((item): item is number => typeof item === "number");
}

function normalizeYonetimKullanici(data: unknown): YonetimKullanici {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Kullanici yaniti beklenen formatta degil.");
  }

  const id = readNumber(record.id);
  const adSoyad = readString(record.ad_soyad);
  if (!id || !adSoyad) {
    throw new Error("Kullanici yaniti zorunlu alanlari icermiyor.");
  }

  const mustChangePassword = readOptionalBoolean(
    record.must_change_password ?? record.mustChangePassword
  );
  return {
    id,
    username: readString(record.username),
    ad_soyad: adSoyad,
    telefon: readString(record.telefon),
    kullanici_tipi: normalizeKullaniciTipi(record.kullanici_tipi),
    rol: normalizeUserRole(record.rol),
    personel_id: readNumber(record.personel_id) ?? null,
    personel_ad_soyad: readStringOrNull(record.personel_ad_soyad),
    ...(mustChangePassword === undefined ? {} : { must_change_password: mustChangePassword }),
    sube_ids: readNumberArray(record.sube_ids),
    bolum_ids: readNumberArray(record.bolum_ids),
    birim_ids: readNumberArray(record.birim_ids),
    sirket_ids: readNumberArray(record.sirket_ids),
    sgk_isveren_ids: readNumberArray(record.sgk_isveren_ids),
    varsayilan_sube_id: readNumber(record.varsayilan_sube_id) ?? null,
    durum: normalizeKayitDurumu(record.durum),
    notlar: readString(record.notlar)
  };
}

function normalizeOrgRelation(data: unknown): YonetimOrgRelation | null {
  const record = toRecord(data);
  if (!record) {
    return null;
  }
  const id = readNumber(record.id);
  const ad = readString(record.ad);
  if (!id || !ad) {
    return null;
  }

  return { id, kod: readString(record.kod) ?? null, ad };
}

function normalizeYonetimSirket(data: unknown): YonetimSirket {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Sirket yaniti beklenen formatta degil.");
  }

  const id = readNumber(record.id);
  const kod = readString(record.kod);
  const ad = readString(record.ad);
  if (!id || !kod || !ad) {
    throw new Error("Sirket yaniti zorunlu alanlari icermiyor.");
  }

  return {
    id,
    kod,
    ad,
    durum: normalizeKayitDurumu(record.durum),
    sube_sayisi: readNumber(record.sube_sayisi) ?? 0
  };
}

function normalizeYonetimSgkIsveren(data: unknown): YonetimSgkIsveren {
  const record = toRecord(data);
  if (!record) {
    throw new Error("SGK isveren yaniti beklenen formatta degil.");
  }

  const id = readNumber(record.id);
  const ad = readString(record.ad);
  if (!id || !ad) {
    throw new Error("SGK isveren yaniti zorunlu alanlari icermiyor.");
  }

  // Nested relation is the canonical read shape; the flat sirket_id/sirket_ad
  // projection keeps an employer readable on a schema without the join.
  const nestedSirket = normalizeOrgRelation(record.sirket);
  const flatSirketId = readNumber(record.sirket_id ?? record.sirketId);
  const flatSirketAd = readString(record.sirket_ad ?? record.sirketAd);
  const sirket =
    nestedSirket ??
    (flatSirketId !== undefined && flatSirketId > 0 && flatSirketAd !== undefined
      ? { id: flatSirketId, kod: readString(record.sirket_kod ?? record.sirketKod) ?? null, ad: flatSirketAd }
      : null);

  return {
    id,
    kod: readString(record.kod) ?? null,
    ad,
    durum: normalizeKayitDurumu(record.durum),
    sirket,
    sube_sayisi: readNumber(record.sube_sayisi) ?? 0
  };
}

function normalizeYonetimSube(data: unknown): YonetimSube {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Sube yaniti beklenen formatta degil.");
  }

  const id = readNumber(record.id);
  const kod = readString(record.kod);
  const ad = readString(record.ad);
  if (!id || !kod || !ad) {
    throw new Error("Sube yaniti zorunlu alanlari icermiyor.");
  }

  // Şube listesi ve şirket bazlı görünüm şubenin kendi şirket bağına dayanır.
  // Prefer the nested read-model relation; fall back to the flat sirket_id +
  // sirket_ad projection so a şube whose sirket relation is missing still keeps
  // its company link instead of silently dropping out of the company view.
  // (Şube SGK işvereni seçimi bu bağdan bağımsızdır: sgk-isveren-options.ts.)
  const nestedSirket = normalizeOrgRelation(record.sirket);
  const flatSirketId = readNumber(record.sirket_id);
  const flatSirketAd = readString(record.sirket_ad ?? record.sirketAd);
  const sirket =
    nestedSirket ??
    (flatSirketId !== undefined &&
      flatSirketId > 0 &&
      flatSirketAd !== undefined &&
      flatSirketAd.trim() !== ""
      ? {
          id: flatSirketId,
          kod: readString(record.sirket_kod ?? record.sirketKod) ?? null,
          ad: flatSirketAd
        }
      : null);

  return {
    id,
    kod,
    ad,
    // Never rebuilt here: the backend read model owns the derived display name.
    // On a legacy/unmapped record it already equals the raw short name.
    tam_ad: readString(record.tam_ad) ?? ad,
    sirket,
    sgk_isveren: normalizeOrgRelation(record.sgk_isveren),
    departman_ids: readNumberArray(record.departman_ids),
    departman_adlari: Array.isArray(record.departman_adlari)
      ? record.departman_adlari
          .map((item) => readString(item))
          .filter((item): item is string => typeof item === "string")
      : Array.isArray(record.departmanlar)
        ? record.departmanlar
            .map((item) => readString(item))
            .filter((item): item is string => typeof item === "string")
        : [],
    durum: normalizeKayitDurumu(record.durum)
  };
}

function normalizeAylikBolumOnayDurumu(value: unknown): AylikBolumOnayDurumu {
  if (value === "BOLUM_ONAYLANDI" || value === "REVIZE_ISTENDI") {
    return value;
  }
  if (value === "KAPANDI") {
    return "BOLUM_ONAYLANDI";
  }
  return "BOLUM_ONAYINDA";
}

function normalizeAylikAggregateState(value: unknown): AylikOzetAggregateState {
  if (
    value === "BOLUM_ONAYLANDI" ||
    value === "REVIZE_ISTENDI" ||
    value === "KAPANDI" ||
    value === "BOLUM_ONAYINDA"
  ) {
    return value;
  }
  return "BOLUM_ONAYINDA";
}

function buildAylikOzetResponse(
  filters: AylikOzetFilters,
  data: Record<string, unknown> | null | undefined
): AylikOzetResponse {
  const ay = readString(data?.ay) ?? filters.ay;
  return {
    ay,
    state: normalizeAylikAggregateState(data?.state),
    summary: normalizeAylikOzetSummary(data?.summary),
    items: extractListItems(data?.items).map(normalizeAylikOzetRow),
    pending_bolum_onayi: readNumber(data?.pending_bolum_onayi) ?? 0
  };
}

function normalizeAylikOzetSummary(data: unknown): AylikOzetSummary {
  const record = toRecord(data);
  return {
    toplam_personel: readNumber(record?.toplam_personel) ?? 0,
    toplam_devamsizlik_gun: readNumber(record?.toplam_devamsizlik_gun) ?? 0,
    toplam_gec_kalma: readNumber(record?.toplam_gec_kalma) ?? 0,
    toplam_izinli_gelmedi: readNumber(record?.toplam_izinli_gelmedi) ?? 0,
    toplam_izinsiz_gelmedi: readNumber(record?.toplam_izinsiz_gelmedi) ?? 0,
    toplam_raporlu: readNumber(record?.toplam_raporlu) ?? 0,
    toplam_tesvik_tutari: readNumber(record?.toplam_tesvik_tutari) ?? 0,
    toplam_ceza_kesinti_tutari: readNumber(record?.toplam_ceza_kesinti_tutari) ?? 0
  };
}

function normalizeAylikOzetRow(data: unknown): AylikOzetRow {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Aylik ozet satiri beklenen formatta degil.");
  }

  const personelId = readNumber(record.personel_id);
  const adSoyad = readString(record.ad_soyad);
  if (!personelId || !adSoyad) {
    throw new Error("Aylik ozet satiri zorunlu alanlari icermiyor.");
  }

  return {
    personel_id: personelId,
    ad_soyad: adSoyad,
    sicil_no: readString(record.sicil_no),
    sube: readString(record.sube) ?? "-",
    bolum: readString(record.bolum) ?? "-",
    bagli_amir_adi: readString(record.bagli_amir_adi) ?? "-",
    devamsizlik_gun: readNumber(record.devamsizlik_gun) ?? 0,
    gec_kalma_adet: readNumber(record.gec_kalma_adet) ?? 0,
    izinli_gelmedi: readNumber(record.izinli_gelmedi) ?? 0,
    izinsiz_gelmedi: readNumber(record.izinsiz_gelmedi) ?? 0,
    raporlu: readNumber(record.raporlu) ?? 0,
    tesvik_tutari: readNumber(record.tesvik_tutari) ?? 0,
    ceza_kesinti_tutari: readNumber(record.ceza_kesinti_tutari) ?? 0,
    bolum_onay_durumu: normalizeAylikBolumOnayDurumu(record.bolum_onay_durumu),
    revize_var_mi: readBoolean(record.revize_var_mi),
    son_islem: readString(record.son_islem) ?? "-",
    kapanis_durumu: record.kapanis_durumu === "KAPANDI" ? "KAPANDI" : "ACIK"
  };
}

export async function fetchYonetimKullanicilari(): Promise<YonetimKullanici[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullanicilar);
  return extractListItems(response.data).map(normalizeYonetimKullanici);
}

export async function createYonetimKullanici(
  payload: UpsertYonetimKullaniciPayload
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullanicilar, {
    method: "POST",
    body: JSON.stringify(sanitizeYonetimKullaniciPayloadForApi(payload))
  });
  return normalizeYonetimKullanici(response.data);
}

export async function updateYonetimKullanici(
  kullaniciId: number | string,
  payload: UpsertYonetimKullaniciPayload
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullaniciDetail(kullaniciId), {
    method: "PUT",
    body: JSON.stringify(sanitizeYonetimKullaniciPayloadForApi(payload))
  });
  return normalizeYonetimKullanici(response.data);
}

/**
 * Canonical admin credential reset: boolean intent only.
 * Never sends a password or a hash; the server derives the initial password from
 * the account's own stored name and forces a change on first login.
 */
export async function resetYonetimKullaniciBaslangicSifresi(
  kullaniciId: number | string
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullaniciDetail(kullaniciId), {
    method: "PUT",
    body: JSON.stringify({ baslangic_sifresine_sifirla: true })
  });
  return normalizeYonetimKullanici(response.data);
}

/**
 * Rewrite username to the bound-personel canonical template.
 * Password / must_change_password are intentionally untouched.
 */
export async function fixYonetimKullaniciCanonicalUsername(
  kullaniciId: number | string
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullaniciDetail(kullaniciId), {
    method: "PUT",
    body: JSON.stringify({ canonical_username_duzelt: true })
  });
  return normalizeYonetimKullanici(response.data);
}

export async function createYonetimActorIdentity(userId: number | string): Promise<YonetimActorIdentityRead> {
  const response = await apiRequest<ApiResponse<YonetimActorIdentityRead>>(endpoints.yonetim.actorIdentities, {
    method: "POST",
    body: JSON.stringify({ user_id: userId })
  });
  return response.data;
}

export async function verifyYonetimActorIdentity(
  actorIdentityId: number | string
): Promise<YonetimActorIdentityRead> {
  const response = await apiRequest<ApiResponse<YonetimActorIdentityRead>>(
    endpoints.yonetim.actorIdentityVerify(actorIdentityId),
    { method: "POST", body: JSON.stringify({}) }
  );
  return response.data;
}

export async function bindYonetimActorIdentity(
  userId: number | string,
  actorIdentityId: number | string
): Promise<YonetimActorIdentityRead> {
  const response = await apiRequest<ApiResponse<YonetimActorIdentityRead>>(
    endpoints.yonetim.kullaniciActorIdentity(userId),
    { method: "POST", body: JSON.stringify({ actor_identity_id: actorIdentityId }) }
  );
  return response.data;
}

export async function fetchYonetimActorIdentity(userId: number | string): Promise<YonetimActorIdentityRead> {
  const response = await apiRequest<ApiResponse<YonetimActorIdentityRead>>(
    endpoints.yonetim.kullaniciActorIdentity(userId)
  );
  return response.data;
}

export async function fetchYonetimSubeleri(): Promise<YonetimSube[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeler);
  return extractListItems(response.data).map(normalizeYonetimSube);
}

export async function createYonetimSube(payload: UpsertYonetimSubePayload): Promise<YonetimSube> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeler, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSube(response.data);
}

export async function updateYonetimSube(
  subeId: number | string,
  payload: UpsertYonetimSubePayload
): Promise<YonetimSube> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeDetail(subeId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSube(response.data);
}

function throwYonetimApiError(response: ApiResponse<unknown>, fallbackMessage: string): never {
  const first = Array.isArray(response.errors) ? response.errors[0] : null;
  const message = typeof first?.message === "string" && first.message.trim() ? first.message : fallbackMessage;
  const code = typeof first?.code === "string" ? first.code : undefined;
  const conflictCodes = [SUBE_DELETE_BLOCKED_ERROR_CODE, "SIRKET_HAS_DEPENDENTS", "SUBE_HAS_LOKASYON"];
  const status = code && conflictCodes.includes(code) ? 409 : 400;

  throw new ApiRequestError(message, status, code ? { code } : undefined);
}

export async function deleteYonetimSube(subeId: number | string): Promise<void> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeDetail(subeId), {
    method: "DELETE"
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwYonetimApiError(response, "Şube silinemedi.");
  }
}

export async function fetchOrganizasyonReadiness(): Promise<OrganizasyonReadiness> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.organizasyonReadiness);
  const record = toRecord(response.data) ?? {};
  const counts = toRecord(record.counts) ?? {};

  return {
    schema_ready: record.schema_ready === true,
    data_ready: record.data_ready === true,
    counts: {
      sirket_count: readNumber(counts.sirket_count) ?? 0,
      sube_count: readNumber(counts.sube_count) ?? 0,
      unmapped_sube_count: readNumber(counts.unmapped_sube_count) ?? 0,
      unmapped_sgk_isveren_count: readNumber(counts.unmapped_sgk_isveren_count) ?? 0,
      orphan_sube_sirket_count: readNumber(counts.orphan_sube_sirket_count) ?? 0,
      orphan_lokasyon_sube_count: readNumber(counts.orphan_lokasyon_sube_count) ?? 0
    },
    blockers: Array.isArray(record.blockers)
      ? record.blockers.map((item) => readString(item)).filter((item): item is string => typeof item === "string")
      : []
  };
}

export async function fetchYonetimSirketleri(): Promise<YonetimSirket[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sirketler);
  return extractListItems(response.data).map(normalizeYonetimSirket);
}

export async function createYonetimSirket(payload: UpsertYonetimSirketPayload): Promise<YonetimSirket> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sirketler, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSirket(response.data);
}

export async function updateYonetimSirket(
  sirketId: number | string,
  payload: UpsertYonetimSirketPayload
): Promise<YonetimSirket> {
  // `kod` is immutable server-side; it is never part of an update payload.
  const { kod: _immutableKod, ...rest } = payload;
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sirketDetail(sirketId), {
    method: "PUT",
    body: JSON.stringify(rest)
  });
  return normalizeYonetimSirket(response.data);
}

export async function deleteYonetimSirket(sirketId: number | string): Promise<void> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sirketDetail(sirketId), {
    method: "DELETE"
  });
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwYonetimApiError(response, "Şirket silinemedi.");
  }
}

export async function fetchSirketSubeleri(sirketId: number | string): Promise<YonetimSube[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sirketSubeler(sirketId));
  const record = toRecord(response.data);
  return extractListItems(record?.items ?? response.data).map(normalizeYonetimSube);
}

export async function createSirketSube(
  sirketId: number | string,
  payload: UpsertYonetimSubePayload
): Promise<YonetimSube> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sirketSubeler(sirketId), {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSube(response.data);
}

export async function updateSirketSube(
  sirketId: number | string,
  subeId: number | string,
  payload: UpsertYonetimSubePayload
): Promise<YonetimSube> {
  // Branch `kod` is immutable on edit, mirroring the server-side rule.
  const { kod: _immutableKod, ...rest } = payload;
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.yonetim.sirketSubeDetail(sirketId, subeId),
    { method: "PUT", body: JSON.stringify(rest) }
  );
  return normalizeYonetimSube(response.data);
}

export async function deleteSirketSube(
  sirketId: number | string,
  subeId: number | string
): Promise<void> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.yonetim.sirketSubeDetail(sirketId, subeId),
    { method: "DELETE" }
  );
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwYonetimApiError(response, "Şube silinemedi.");
  }
}

/**
 * Management read of the SGK employer catalog: PASIF rows and the owning company
 * are part of the contract, unlike the AKTIF-only /referans projection.
 */
export async function fetchYonetimSgkIsverenleri(): Promise<YonetimSgkIsveren[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sgkIsverenler);
  const record = toRecord(response.data);
  return extractListItems(record?.items ?? response.data).map(normalizeYonetimSgkIsveren);
}

export async function createYonetimSgkIsveren(
  payload: UpsertYonetimSgkIsverenPayload
): Promise<YonetimSgkIsveren> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sgkIsverenler, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSgkIsveren(response.data);
}

export async function updateYonetimSgkIsveren(
  sgkIsverenId: number | string,
  payload: UpsertYonetimSgkIsverenPayload
): Promise<YonetimSgkIsveren> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sgkIsverenDetail(sgkIsverenId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSgkIsveren(response.data);
}

export async function deleteYonetimSgkIsveren(sgkIsverenId: number | string): Promise<void> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.sgkIsverenDetail(sgkIsverenId), {
    method: "DELETE"
  });
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwYonetimApiError(response, "SGK işvereni silinemedi.");
  }
}

export async function fetchAylikKapanisOzeti(filters: AylikOzetFilters): Promise<AylikOzetResponse> {
  const path = appendQueryParams(endpoints.yonetim.aylikOzet, {
    ay: filters.ay,
    sube_id: filters.sube_id,
    departman_id: filters.departman_id,
    sadece_revizeli: filters.sadece_revizeli
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const data = toRecord(response.data);
  return buildAylikOzetResponse(filters, data);
}

export async function bolumOnayiVer(filters: AylikOzetFilters): Promise<AylikOzetResponse> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.aylikOzetBolumOnay, {
    method: "POST",
    body: JSON.stringify(filters)
  });
  const data = toRecord(response.data);
  return buildAylikOzetResponse(filters, data);
}

/** Genel yönetici üst kontrol onayı (`/yonetim/aylik-ozet/ay-kapat`). State KAPANDI yalnızca bu akışta oluşur. */
export async function ustOnayVer(filters: AylikOzetFilters): Promise<AylikOzetResponse> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.aylikOzetKapat, {
    method: "POST",
    body: JSON.stringify(filters)
  });
  const data = toRecord(response.data);
  return buildAylikOzetResponse(filters, data);
}

function buildPersonelHesapOnboardingUser(userRecord: Record<string, unknown> | null): {
  record: PersonelHesapOnboardingUser;
  userId: number;
  username: string;
} | null {
  const userId = userRecord ? readNumber(userRecord.id) : undefined;
  const username = userRecord ? readString(userRecord.username) : undefined;
  if (!userId || !username) {
    return null;
  }

  const mustChangePassword = userRecord
    ? readOptionalBoolean(userRecord.must_change_password)
    : undefined;

  return {
    userId,
    username,
    record: {
      id: userId,
      username,
      rol: userRecord ? readString(userRecord.rol) : undefined,
      durum: userRecord ? readString(userRecord.durum) : undefined,
      personel_id: userRecord ? (readNumber(userRecord.personel_id) ?? null) : null,
      ...(mustChangePassword === undefined ? {} : { must_change_password: mustChangePassword }),
    }
  };
}

/**
 * Canonical yeni hesap create yolu: yalniz user bilgisi + ilk giris modeli doner.
 * Aktivasyon URL'i zorunlu degildir ve secret tasinmaz.
 */
function normalizePersonelHesapFirstLoginResult(data: unknown): PersonelHesapFirstLoginResult {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Personel hesap onboarding yaniti beklenen formatta degil.");
  }

  const built = buildPersonelHesapOnboardingUser(toRecord(record.user));
  if (!built) {
    throw new Error("Personel hesap onboarding yaniti zorunlu alanlari icermiyor.");
  }

  return {
    user: built.record,
    credential_model: readString(record.credential_model),
    message: readString(record.message)
  };
}

export async function createPersonelHesapOnboarding(
  personelId: number | string,
  usernameOverride?: string
): Promise<PersonelHesapFirstLoginResult> {
  const body =
    usernameOverride !== undefined && usernameOverride.trim() !== ""
      ? { username: usernameOverride.trim() }
      : {};
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.yonetim.personelHesapOnboarding(personelId),
    { method: "POST", body: JSON.stringify(body) }
  );
  return normalizePersonelHesapFirstLoginResult(response.data);
}
