/** Parse one-time activation token from location hash (`#token=...`). Never persist. */
export function extractPersonelActivationTokenFromHash(hash: string): string | null {
  const raw = hash.startsWith("#") ? hash.slice(1) : hash;
  if (!raw) {
    return null;
  }
  const params = new URLSearchParams(raw);
  const token = params.get("token");
  if (!token) {
    return null;
  }
  const trimmed = token.trim();
  return trimmed.length > 0 ? trimmed : null;
}

/** Strip hash from the address bar without navigation (token stays in React state only). */
export function clearPersonelActivationLocationHash(
  replaceState: (data: unknown, unused: string, url?: string | null) => void = window.history.replaceState.bind(
    window.history
  ),
  locationLike: Pick<Location, "pathname" | "search"> = window.location
): void {
  replaceState(null, "", `${locationLike.pathname}${locationLike.search}`);
}
