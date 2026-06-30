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
 * Whether a 403 response should emit the global auth-forbidden event (/yetkisiz redirect).
 * Default true: unknown endpoints keep the existing global forbidden behavior.
 */
export function shouldEmitGlobalAuthForbidden(path: string, method?: string): boolean {
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

  if (normalizedMethod === "GET" && /^\/surecler\/\d+$/.test(normalizedPath)) {
    return false;
  }

  if (normalizedMethod === "GET" && /^\/bildirimler\/\d+$/.test(normalizedPath)) {
    return false;
  }

  return true;
}
