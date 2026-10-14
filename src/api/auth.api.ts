import { finalizeAuthSessionSube } from "../auth/auth-session-sube";
import { isRealBackendOnlyMode } from "../config/app-env";
import { canonicalizeUserRole } from "../lib/authorization/canonicalize-user-role";
import { getEffectivePermissions } from "../lib/authorization/role-permissions";
import { resolveSubeDisplayLabels } from "../lib/organizasyon/sube-display-label";
import { flushPreAuthTelemetry } from "../logging/client-telemetry";
import type { AuthSession, ExpiringPermissionAllow, LoginCredentials } from "../types/auth";
import { apiRequest, ApiRequestError } from "./api-client";
import { endpoints } from "./endpoints";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function readString(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function readNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
}

function normalizeRole(value: unknown): AuthSession["user"]["rol"] | null {
  return canonicalizeUserRole(value);
}

function deriveUiProfile(role: AuthSession["user"]["rol"]): AuthSession["ui_profile"] {
  return role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim";
}

function readSubeIds(record: Record<string, unknown>): number[] {
  const raw =
    record.sube_ids ??
    record.subeIds ??
    record.branch_ids ??
    record.branchIds ??
    record.allowed_sube_ids;
  if (!Array.isArray(raw)) {
    return [];
  }
  const ids: number[] = [];
  for (const item of raw) {
    const n = readNumber(item);
    if (n !== null) {
      ids.push(n);
    }
  }
  return ids;
}

function readIdList(record: Record<string, unknown>, key: string): number[] {
  const raw = record[key];
  if (!Array.isArray(raw)) {
    return [];
  }
  const ids: number[] = [];
  for (const item of raw) {
    const n = readNumber(item);
    if (n !== null && n > 0) {
      ids.push(n);
    }
  }
  return ids;
}

function readSubeList(record: Record<string, unknown>): AuthSession["sube_list"] {
  const raw = record.sube_list ?? record.subeler ?? record.branches;
  if (!Array.isArray(raw)) {
    return undefined;
  }
  const out: NonNullable<AuthSession["sube_list"]> = [];
  for (const item of raw) {
    if (typeof item !== "object" || item === null) {
      continue;
    }
    const row = item as Record<string, unknown>;
    const id = readNumber(row.id);
    // `tam_ad` wins when the backend derived one; older payloads and mocks only
    // carry `ad`, which is then already the display name.
    const ad =
      readString(row.tam_ad) ??
      readString(row.ad) ??
      readString(row.name) ??
      readString(row.label) ??
      (id !== null ? `Şube ${id}` : null);
    if (id !== null && ad) {
      out.push({
        id,
        ad,
        kisa_ad: readString(row.kisa_ad) ?? readString(row.ad) ?? undefined,
        tam_ad: readString(row.tam_ad) ?? undefined,
        sirket_id: readNumber(toRecord(row.sirket)?.id ?? row.sirket_id)
      });
    }
  }
  if (out.length === 0) {
    return undefined;
  }

  // Adaptive kanonik şube etiketi: kısa ad benzersizse kısa ad, aynı kısa ad
  // birden fazla şirkette varsa backend'in türettiği `tam_ad` (ön ek/elle
  // birleştirme yok). `ad` bu oturumun görünen etiketidir; `kisa_ad`/`tam_ad`
  // alanları olduğu gibi korunur.
  const labels = resolveSubeDisplayLabels(
    out.map((item) => ({
      id: item.id,
      ad: item.kisa_ad ?? item.ad,
      tamAd: item.tam_ad ?? null
    }))
  );

  return out.map((item) => ({ ...item, ad: labels.get(item.id) ?? item.ad }));
}

function readPermissionList(value: unknown): string[] | null {
  if (!Array.isArray(value)) {
    return null;
  }
  const list: string[] = [];
  for (const item of value) {
    if (typeof item !== "string") {
      continue;
    }
    const trimmed = item.trim();
    if (trimmed) {
      list.push(trimmed);
    }
  }
  return list;
}

function earliestPermissionExpiryMs(value: unknown): number | null {
  if (!Array.isArray(value)) {
    return null;
  }
  let earliest: number | null = null;
  for (const item of value) {
    const record = toRecord(item);
    const bitis = record ? readString(record.gecerlilik_bitis) : null;
    if (!bitis) {
      continue;
    }
    const parsed = permissionInstantMs(bitis);
    if (parsed === null) {
      continue;
    }
    if (earliest === null || parsed < earliest) {
      earliest = parsed;
    }
  }
  return earliest;
}

export type SessionPermissionSnapshot = {
  permissions: string[];
  refreshAtMs: number | null;
  expiringAllows: ExpiringPermissionAllow[];
};

function permissionInstantMs(value: string): number | null {
  const parsed = Date.parse(value.includes("T") ? value : `${value.replace(" ", "T")}Z`);
  return Number.isFinite(parsed) ? parsed : null;
}

/** Son okumada yalnız USER_ALLOW ve bitişi belli olan izinler. Kaynak yoksa tahmin edilmez. */
export function readExpiringAllows(source: Record<string, unknown>): ExpiringPermissionAllow[] {
  const kaynaklar = toRecord(source.kaynaklar);
  const rows = source.istisnalar;
  if (!kaynaklar || !Array.isArray(rows)) {
    return [];
  }
  const out: ExpiringPermissionAllow[] = [];
  for (const item of rows) {
    const record = toRecord(item);
    if (!record || readString(record.etki) !== "ALLOW") {
      continue;
    }
    const permission = readString(record.permission);
    const bitis = readString(record.gecerlilik_bitis);
    if (!permission || !bitis || kaynaklar[permission] !== "USER_ALLOW") {
      continue;
    }
    const endsAtMs = permissionInstantMs(bitis);
    if (endsAtMs === null) {
      continue;
    }
    out.push({ permission, ends_at_ms: endsAtMs });
  }
  return out;
}

/**
 * Süresi dolmuş kişisel ALLOW'ı listeden çıkarır.
 * Rol izni eklemez; DENY ile kapanmış izin geri açılmaz.
 * Değişiklik yoksa null.
 */
export function dropExpiredAllows(session: AuthSession, nowMs: number): AuthSession | null {
  if (session.permissions_hydrated !== true) {
    return null;
  }
  const expiring = session.expiring_allows;
  const list = session.user.effective_permissions;
  if (!Array.isArray(expiring) || expiring.length === 0 || !Array.isArray(list)) {
    return null;
  }
  const due = new Set(expiring.filter((item) => item.ends_at_ms <= nowMs).map((item) => item.permission));
  if (due.size === 0) {
    return null;
  }
  return {
    ...session,
    expiring_allows: expiring.filter((item) => item.ends_at_ms > nowMs),
    user: {
      ...session.user,
      effective_permissions: list.filter((permission) => !due.has(permission))
    }
  };
}

/** GET /auth/yetkiler. Anahtar yoksa boş liste sayılmaz. */
export async function fetchSessionPermissions(): Promise<SessionPermissionSnapshot> {
  const response = await apiRequest<unknown>(endpoints.auth.yetkiler);
  const root = toRecord(response);
  const source = toRecord(root?.data) ?? root;
  if (!source || !Object.prototype.hasOwnProperty.call(source, "effective_permissions")) {
    throw new ApiRequestError("Etkin izin listesi doğrulanamadı.", 200, { code: "INVALID_RESPONSE" });
  }
  const permissions = readPermissionList(source.effective_permissions);
  if (!permissions) {
    throw new ApiRequestError("Etkin izin listesi doğrulanamadı.", 200, { code: "INVALID_RESPONSE" });
  }
  return {
    permissions,
    refreshAtMs: earliestPermissionExpiryMs(source.istisnalar),
    expiringAllows: readExpiringAllows(source)
  };
}

function extractErrorMessage(payload: unknown): string | null {
  const record = toRecord(payload);
  if (!record) {
    return null;
  }

  const directMessage = readString(record.message);
  if (directMessage) {
    return directMessage;
  }

  const errors = record.errors;
  if (!Array.isArray(errors) || errors.length === 0) {
    return null;
  }

  const firstError = toRecord(errors[0]);
  return firstError ? readString(firstError.message) : null;
}

function normalizeAuthSession(payload: unknown): AuthSession | null {
  const root = toRecord(payload);
  if (!root) {
    return null;
  }

  const source = toRecord(root.data) ?? root;
  const token =
    readString(source.token) ??
    readString(source.access_token) ??
    readString(source.accessToken) ??
    readString(source.jwt);

  const userSource = toRecord(source.user) ?? source;
  const userId = readNumber(userSource.id) ?? readNumber(userSource.user_id);
  const role = normalizeRole(userSource.rol ?? userSource.role ?? source.rol ?? source.role);
  const fullName =
    readString(userSource.ad_soyad) ??
    readString(userSource.adSoyad) ??
    readString(userSource.full_name) ??
    readString(userSource.fullName) ??
    readString(userSource.name) ??
    readString(userSource.ad);

  const uiProfileRaw = readString(source.ui_profile) ?? readString(source.uiProfile);
  let uiProfile: AuthSession["ui_profile"] | null = null;
  if (uiProfileRaw === "yonetim" || uiProfileRaw === "birim_amiri") {
    uiProfile = uiProfileRaw;
  } else if (uiProfileRaw === "birim") {
    uiProfile = "birim_amiri";
  } else if (role) {
    uiProfile = deriveUiProfile(role);
  }

  if (!token || userId === null || !fullName || !role || !uiProfile) {
    return null;
  }

  const fromUser = readSubeIds(userSource ?? {});
  const fromRoot = readSubeIds(toRecord(source) ?? {});
  const sube_ids = fromUser.length > 0 ? fromUser : fromRoot;
  const sube_list = readSubeList(toRecord(source) ?? {});

  const preferredActive =
    readNumber(userSource.active_sube_id) ??
    readNumber(userSource.activeSubeId) ??
    readNumber(source.active_sube_id) ??
    readNumber(source.activeSubeId);

  const personelIdRaw =
    readNumber(userSource.personel_id) ??
    readNumber(userSource.personelId) ??
    readNumber(source.personel_id) ??
    readNumber(source.personelId);
  const personel_id =
    userSource.personel_id === null ||
    userSource.personelId === null ||
    source.personel_id === null ||
    source.personelId === null
      ? null
      : personelIdRaw ?? null;

  const mustChangeRaw = source.must_change_password ?? source.mustChangePassword;
  const must_change_password =
    mustChangeRaw === true || mustChangeRaw === 1 || mustChangeRaw === "1" || mustChangeRaw === "true"
      ? true
      : mustChangeRaw === false || mustChangeRaw === 0 || mustChangeRaw === "0" || mustChangeRaw === "false"
        ? false
        : undefined;

  const draft: AuthSession = {
    token,
    ui_profile: uiProfile,
    sube_list,
    active_sube_id: preferredActive,
    must_change_password,
    user: {
      id: userId,
      ad_soyad: fullName,
      rol: role,
      sube_ids,
      // Absent in legacy sessions and mock payloads: an empty array is the safe
      // fallback, never an implicit grant.
      explicit_sube_ids: readIdList(userSource, "explicit_sube_ids"),
      sirket_ids: readIdList(userSource, "sirket_ids"),
      sgk_isveren_ids: readIdList(userSource, "sgk_isveren_ids"),
      personel_id,
      // Canonical collar mirror from the login payload (backend read model).
      // Absent in legacy sessions: the PERSONEL QR gate then fails closed.
      personel_tipi_id: readNumber(userSource.personel_tipi_id ?? userSource.personelTipiId),
      personel_tipi_ad:
        readString(userSource.personel_tipi_ad) ?? readString(userSource.personelTipiAd)
    }
  };

  const permissionSource = userSource.effective_permissions ?? userSource.effectivePermissions;
  const permissionKeyPresent =
    Object.prototype.hasOwnProperty.call(userSource, "effective_permissions") ||
    Object.prototype.hasOwnProperty.call(userSource, "effectivePermissions");
  const permissions = permissionKeyPresent ? readPermissionList(permissionSource) : null;
  if (permissions) {
    draft.user.effective_permissions = permissions;
    // Liste tek başına süreli ALLOW'ın bitişini kanıtlamaz. Eski/eksik login
    // yanıtı ilk tam snapshot okunana kadar production'da kapalı kalır.
    draft.permissions_hydrated = toRecord(userSource.kaynaklar) !== null && Array.isArray(userSource.istisnalar);
    draft.permissions_stale = false;
    draft.permissions_source = "server";
    if (draft.permissions_hydrated) {
      draft.expiring_allows = readExpiringAllows(userSource);
    }
  } else if (permissionKeyPresent && Array.isArray(permissionSource) && permissionSource.length === 0) {
    draft.user.effective_permissions = [];
    draft.permissions_hydrated = true;
    draft.permissions_stale = false;
    draft.permissions_source = "server";
  }

  return finalizeAuthSessionSube(draft);
}

function resolveDemoRole(username: string): AuthSession["user"]["rol"] {
  const normalized = username.trim().toLowerCase();
  if (normalized.includes("birim")) {
    return "BIRIM_AMIRI";
  }
  if (normalized.includes("muhasebe")) {
    return "MUHASEBE";
  }
  if (normalized.includes("bolum") || normalized.includes("bölüm")) {
    return "BOLUM_YONETICISI";
  }
  if (normalized.includes("ik") || normalized.includes("bordro")) {
    return "IK_SORUMLUSU";
  }
  if (normalized.includes("personel")) {
    return "PERSONEL";
  }
  if (normalized.includes("sistem")) {
    return "SISTEM_YONETICISI";
  }
  if (normalized.includes("patron")) {
    return "GENEL_YONETICI";
  }

  return "GENEL_YONETICI";
}

function toDisplayName(username: string): string {
  const trimmed = username.trim();
  if (!trimmed) {
    return "Demo Kullanici";
  }

  return trimmed;
}

function createDemoSession(credentials: LoginCredentials): AuthSession {
  const role = resolveDemoRole(credentials.username);
  const userId =
    credentials.username
      .split("")
      .reduce((acc, ch) => acc + ch.charCodeAt(0), 0) % 10_000 || 1;

  const sube_ids = role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : [];

  const sube_list: NonNullable<AuthSession["sube_list"]> =
    sube_ids.length === 0
      ? []
    : sube_ids.map((id) => ({ id, ad: id === 1 ? "Merkez" : `Şube ${id}` }));

  return finalizeAuthSessionSube({
    token: "demo-token",
    ui_profile: deriveUiProfile(role),
    sube_list: sube_list.length ? sube_list : undefined,
    active_sube_id: null,
    permissions_hydrated: true,
    permissions_stale: false,
    permissions_source: "demo",
    user: {
      id: userId,
      ad_soyad: toDisplayName(credentials.username),
      rol: role,
      sube_ids,
      effective_permissions: [...getEffectivePermissions(role)]
    }
  });
}

function shouldUseDemoLoginFallback(error: unknown): boolean {
  if (isRealBackendOnlyMode()) {
    return false;
  }

  if (error instanceof ApiRequestError) {
    // Demo/mock modda 404/5xx gibi hatalarda demo session'a dus.
    return [0, 404, 500, 502, 503, 504].includes(error.status);
  }

  // Demo/mock modda JSON parse hatasinda demo session'a dus.
  if (error instanceof TypeError) {
    return true;
  }

  return false;
}

export async function login(credentials: LoginCredentials): Promise<AuthSession> {
  try {
    const response = await apiRequest<unknown>(endpoints.auth.login, {
      method: "POST",
      body: JSON.stringify({
        username: credentials.username,
        password: credentials.password
      })
    });

    const session = normalizeAuthSession(response);
    if (session) {
      flushPreAuthTelemetry();
      return session;
    }

    const backendMessage = extractErrorMessage(response);
    if (backendMessage) {
      throw new ApiRequestError(backendMessage, 200);
    }

    // Auth-benzeri payload (token var, rol bozuk vb.) her zaman fail-closed.
    // Yalnizca HTML/beklenmeyen non-auth 200'lerde demo session'a dus.
    if (!isRealBackendOnlyMode() && !responseLooksLikeAuthPayload(response)) {
      const demo = createDemoSession(credentials);
      flushPreAuthTelemetry();
      return demo;
    }

    throw new ApiRequestError("Login yanıtı beklenen oturum formatında değil.", 200);
  } catch (error) {
    if (shouldUseDemoLoginFallback(error)) {
      const demo = createDemoSession(credentials);
      flushPreAuthTelemetry();
      return demo;
    }

    throw error;
  }
}

export type ChangePasswordInput = {
  currentPassword: string;
  newPassword: string;
};

export async function changePassword(input: ChangePasswordInput): Promise<void> {
  await apiRequest<unknown>(endpoints.auth.changePassword, {
    method: "POST",
    body: JSON.stringify({
      current_password: input.currentPassword,
      new_password: input.newPassword
    })
  });
}

function responseLooksLikeAuthPayload(payload: unknown): boolean {
  const root = toRecord(payload);
  if (!root) {
    return false;
  }
  const source = toRecord(root.data) ?? root;
  const token =
    readString(source.token) ??
    readString(source.access_token) ??
    readString(source.accessToken) ??
    readString(source.jwt);
  return token !== null;
}
