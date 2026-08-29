/**
 * Client mirror of the backend PersonelSearchPredicate input contract.
 *
 * Keeping the normalization identical on both sides means the query state (and
 * therefore the cache key) is stable while the user only adds whitespace, so no
 * request is issued for text the server would treat as unchanged.
 */

/** Must stay in sync with PersonelSearchPredicate::MAX_LENGTH. */
export const PERSONEL_SEARCH_MAX_LENGTH = 120;

/** Debounce for the live search input. */
export const PERSONEL_SEARCH_DEBOUNCE_MS = 275;

export function normalizePersonelSearchQuery(raw: string): string {
  const collapsed = raw.replace(/\s+/gu, " ").trim();
  if (collapsed.length <= PERSONEL_SEARCH_MAX_LENGTH) {
    return collapsed;
  }
  return collapsed.slice(0, PERSONEL_SEARCH_MAX_LENGTH).trim();
}

/** Must stay in sync with PersonelSearchPredicate::MAX_TOKENS. */
export const PERSONEL_SEARCH_MAX_TOKENS = 6;

export function tokenizePersonelSearchQuery(raw: string): string[] {
  const normalized = normalizePersonelSearchQuery(raw);
  if (normalized === "") {
    return [];
  }
  return normalized.split(" ").filter(Boolean).slice(0, PERSONEL_SEARCH_MAX_TOKENS);
}

const TURKISH_FOLD: Record<string, string> = {
  ı: "i",
  İ: "i",
  ş: "s",
  Ş: "s",
  ğ: "g",
  Ğ: "g",
  ü: "u",
  Ü: "u",
  ö: "o",
  Ö: "o",
  ç: "c",
  Ç: "c"
};

/** Mirrors the `*_general_ci` folding the database applies to the search expression. */
function foldForSearch(value: string): string {
  return value.replace(/[ıİşŞğĞüÜöÖçÇ]/g, (char) => TURKISH_FOLD[char] ?? char).toLowerCase();
}

export type PersonelSearchCandidate = {
  ad?: string | null;
  soyad?: string | null;
  sicil_no?: string | null;
  tc_kimlik_no?: string | null;
  telefon?: string | null;
};

/**
 * Same semantics as PersonelSearchPredicate: tokens are ANDed, fields are ORed,
 * and `ad` + `soyad` are one concatenated "Ad Soyad" field. Used by the demo and
 * e2e mock backends so they answer like the real API.
 */
export function personelSearchMatches(candidate: PersonelSearchCandidate, raw: string): boolean {
  const tokens = tokenizePersonelSearchQuery(raw);
  if (tokens.length === 0) {
    return true;
  }

  const fields = [
    `${candidate.ad ?? ""} ${candidate.soyad ?? ""}`,
    candidate.sicil_no ?? "",
    candidate.tc_kimlik_no ?? "",
    candidate.telefon ?? ""
  ].map(foldForSearch);

  return tokens.every((token) => {
    const needle = foldForSearch(token);
    return fields.some((field) => field.includes(needle));
  });
}
