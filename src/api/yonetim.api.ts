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
  PersonelActivationMetaResponse,
  PersonelActivationStatus,
  PersonelHesapOnboardingResult,
  UpsertYonetimKullaniciPayload,
  UpsertYonetimSubePayload,
  YonetimActorIdentityRead,
  YonetimKullanici,
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
  const activationRequired = readOptionalBoolean(
    record.activation_required ?? record.activationRequired
  );
  const activationStatusRaw = readString(record.activation_status ?? record.activationStatus);
  const activationStatus: PersonelActivationStatus | undefined =
    activationStatusRaw === "PENDING" || activationStatusRaw === "ACTIVE"
      ? activationStatusRaw
      : activationRequired === true
        ? "PENDING"
        : activationRequired === false
          ? "ACTIVE"
          : undefined;

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
    ...(activationRequired === undefined ? {} : { activation_required: activationRequired }),
    ...(activationStatus === undefined ? {} : { activation_status: activationStatus }),
    activated_at_utc: readStringOrNull(record.activated_at_utc ?? record.activatedAtUtc),
    sube_ids: readNumberArray(record.sube_ids),
    bolum_ids: readNumberArray(record.bolum_ids),
    birim_ids: readNumberArray(record.birim_ids),
    varsayilan_sube_id: readNumber(record.varsayilan_sube_id) ?? null,
    durum: normalizeKayitDurumu(record.durum),
    notlar: readString(record.notlar)
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

  return {
    id,
    kod,
    ad,
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
 * Never sends a password or a hash; the server writes the standard initial
 * password hash and forces a change on first login.
 */
export async function resetYonetimKullaniciStandartSifre(
  kullaniciId: number | string
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullaniciDetail(kullaniciId), {
    method: "PUT",
    body: JSON.stringify({ standart_baslangic_sifresine_sifirla: true })
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
  const status = code === SUBE_DELETE_BLOCKED_ERROR_CODE ? 409 : 400;

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

function normalizePersonelHesapOnboardingResult(data: unknown): PersonelHesapOnboardingResult {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Personel hesap onboarding yaniti beklenen formatta degil.");
  }

  const userRecord = toRecord(record.user);
  const activationRecord = toRecord(record.activation);
  const userId = userRecord ? readNumber(userRecord.id) : undefined;
  const username = userRecord ? readString(userRecord.username) : undefined;
  const activationUrl = activationRecord ? readString(activationRecord.activation_url) : undefined;
  const createdAt = activationRecord ? readString(activationRecord.created_at_utc) : undefined;
  const expiresAt = activationRecord ? readString(activationRecord.expires_at_utc) : undefined;

  if (!userId || !username || !activationUrl || !createdAt || !expiresAt) {
    throw new Error("Personel hesap onboarding yaniti zorunlu alanlari icermiyor.");
  }

  const activationRequired = userRecord
    ? readOptionalBoolean(userRecord.activation_required)
    : undefined;
  const mustChangePassword = userRecord
    ? readOptionalBoolean(userRecord.must_change_password)
    : undefined;

  return {
    user: {
      id: userId,
      username,
      rol: userRecord ? readString(userRecord.rol) : undefined,
      durum: userRecord ? readString(userRecord.durum) : undefined,
      personel_id: userRecord ? (readNumber(userRecord.personel_id) ?? null) : null,
      ...(activationRequired === undefined ? {} : { activation_required: activationRequired }),
      ...(mustChangePassword === undefined ? {} : { must_change_password: mustChangePassword }),
      activated_at_utc: userRecord
        ? readStringOrNull(userRecord.activated_at_utc)
        : null
    },
    activation: {
      activation_url: activationUrl,
      created_at_utc: createdAt,
      expires_at_utc: expiresAt,
      reissued: activationRecord ? readBoolean(activationRecord.reissued) : false
    },
    message: readString(record.message)
  };
}

export async function createPersonelHesapOnboarding(
  personelId: number | string,
  usernameOverride?: string
): Promise<PersonelHesapOnboardingResult> {
  const body =
    usernameOverride !== undefined && usernameOverride.trim() !== ""
      ? { username: usernameOverride.trim() }
      : {};
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.yonetim.personelHesapOnboarding(personelId),
    { method: "POST", body: JSON.stringify(body) }
  );
  return normalizePersonelHesapOnboardingResult(response.data);
}

export async function reissuePersonelAktivasyon(
  kullaniciId: number | string
): Promise<PersonelHesapOnboardingResult> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.yonetim.kullaniciAktivasyonYenile(kullaniciId),
    { method: "POST", body: JSON.stringify({}) }
  );
  return normalizePersonelHesapOnboardingResult(response.data);
}

export async function fetchPersonelAktivasyonMeta(
  kullaniciId: number | string
): Promise<PersonelActivationMetaResponse> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.yonetim.kullaniciAktivasyonMeta(kullaniciId)
  );
  const record = toRecord(response.data);
  const invitation = record ? toRecord(record.activation_invitation) : null;
  if (!invitation) {
    return { activation_invitation: null };
  }

  const createdAt = readString(invitation.created_at_utc);
  const expiresAt = readString(invitation.expires_at_utc);
  if (!createdAt || !expiresAt) {
    return { activation_invitation: null };
  }

  return {
    activation_invitation: {
      invitation_id: readNumber(invitation.invitation_id),
      created_at_utc: createdAt,
      expires_at_utc: expiresAt,
      is_expired: readBoolean(invitation.is_expired),
      is_valid: readBoolean(invitation.is_valid)
    }
  };
}
