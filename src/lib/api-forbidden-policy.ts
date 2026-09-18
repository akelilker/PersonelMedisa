function normalizeRequestMethod(method?: string): string {
  const normalized = (method ?? "GET").trim().toUpperCase();
  return normalized || "GET";
}

/** Strips query string and normalizes to a leading-slash API path segment. */
export function normalizeApiRequestPath(path: string): string {
  const withoutQuery = path.split("?")[0]?.trim() ?? "";
  if (!withoutQuery) {
    return "/";
  }

  const apiPrefixIndex = withoutQuery.indexOf("/api/");
  const basePath =
    apiPrefixIndex >= 0 ? withoutQuery.slice(apiPrefixIndex + "/api".length) : withoutQuery;

  return basePath.startsWith("/") ? basePath : `/${basePath}`;
}

/**
 * Zorunlu sifre degisimi (must_change_password) bir yetki hatasi degildir; kendi
 * `/change-password` rotasi vardir. Bu kod global `/yetkisiz` yonlendirmesini tetiklemez.
 */
const NON_FORBIDDEN_ERROR_CODES = new Set(["PASSWORD_CHANGE_REQUIRED"]);

/**
 * Whether a 403 response should emit the global auth-forbidden event (/yetkisiz redirect).
 * Default true: unknown endpoints keep the existing global forbidden behavior.
 */
export function shouldEmitGlobalAuthForbidden(path: string, method?: string, code?: string | null): boolean {
  const normalizedCode = typeof code === "string" ? code.trim().toUpperCase() : "";
  if (normalizedCode && NON_FORBIDDEN_ERROR_CODES.has(normalizedCode)) {
    return false;
  }

  const normalizedMethod = normalizeRequestMethod(method);
  const normalizedPath = normalizeApiRequestPath(path);

  if (normalizedMethod === "POST" && normalizedPath === "/personeller") {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/personeller\/\d+$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "PUT" && /^\/personeller\/\d+$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && normalizedPath === "/surecler") {
    return false;
  }

  if (
    ["GET", "PUT"].includes(normalizedMethod) &&
    /^\/personeller\/\d+\/belge-durumu$/.test(normalizedPath)
  ) {
    return false;
  }

  if (
    ["GET", "POST"].includes(normalizedMethod) &&
    /^\/personeller\/\d+\/belge-kayitlari$/.test(normalizedPath)
  ) {
    return false;
  }

  if (normalizedMethod === "POST" && /^\/belge-kayitlari\/\d+\/iptal$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "PUT" && /^\/belge-kayitlari\/\d+$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/belge-kayitlari\/\d+$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "POST" && /^\/belge-kayitlari\/\d+\/dosya-degistir$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/belge-kayitlari\/\d+\/gecmis$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/belge-kayitlari\/\d+\/indir$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && normalizedPath === "/belge-takip") {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/surecler\/\d+$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/bildirimler\/\d+$/.test(normalizedPath)) {
    return false;
  }

  // Bootstrap / header preview: yetkisiz roller icin 403 beklenir, global redirect yapma.
  if (normalizedMethod === "GET" && normalizedPath === "/bildirimler") {
    return false;
  }

  if (normalizedMethod === "GET" && normalizedPath === "/personeller") {
    return false;
  }

  // Scoped roller oturumdaki sube_list ile calisir; yonetim listesi 403 beklenen bir fallback'tir.
  if (normalizedMethod === "GET" && normalizedPath === "/yonetim/subeler") {
    return false;
  }

  return true;
}
