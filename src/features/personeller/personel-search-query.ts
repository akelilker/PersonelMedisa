/** Canonical personel free-text search helpers (shared by UI + mock-demo). */

export const PERSONEL_SEARCH_MAX_LENGTH = 120;
export const PERSONEL_SEARCH_DEBOUNCE_MS = 300;

export function normalizePersonelSearchQuery(raw: string): string {
  const collapsed = String(raw ?? "")
    .replace(/\s+/gu, " ")
    .trim();
  if (collapsed.length <= PERSONEL_SEARCH_MAX_LENGTH) {
    return collapsed;
  }
  return collapsed.slice(0, PERSONEL_SEARCH_MAX_LENGTH).trim();
}

function tokenize(raw: string): string[] {
  const normalized = normalizePersonelSearchQuery(raw);
  if (!normalized) return [];
  return normalized.split(" ").filter(Boolean).slice(0, 6);
}

/**
 * Production DB `*_general_ci` katlamasi: Turkce harfler ASCII'ye katlanir.
 * (I/İ/ı/i, ş/Ş, ğ/Ğ, ü/Ü, ö/Ö, ç/Ç) — demo/e2e mock ile gercek API ayni sonucu verir.
 */
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

function foldForSearch(value: string): string {
  return value.replace(/[ıİşŞğĞüÜöÖçÇ]/g, (char) => TURKISH_FOLD[char] ?? char).toLowerCase();
}

function haystack(value: unknown): string {
  return foldForSearch(String(value ?? "").trim());
}

type PersonelSearchable = {
  ad?: string | null;
  soyad?: string | null;
  sicil_no?: string | null;
  tc_kimlik_no?: string | null;
  telefon?: string | null;
  gorev_adi?: string | null;
  bolum_adi?: string | null;
  birim_adi?: string | null;
  sube_adi?: string | null;
  departman_adi?: string | null;
  gorev_id?: number | null;
  bolum_id?: number | null;
  birim_id?: number | null;
  sube_id?: number | null;
};

/**
 * Every token must match at least one field (AND across tokens, OR across fields).
 * Fields: ad+soyad, tc, telefon, sicil, görev/bölüm/birim/şube adları.
 */
export function personelSearchMatches(personel: PersonelSearchable, search: string): boolean {
  const tokens = tokenize(search);
  if (tokens.length === 0) return true;

  const fields = [
    haystack(`${personel.ad ?? ""} ${personel.soyad ?? ""}`),
    haystack(personel.sicil_no),
    haystack(personel.tc_kimlik_no),
    haystack(personel.telefon),
    haystack(personel.gorev_adi),
    haystack(personel.bolum_adi),
    haystack(personel.birim_adi),
    haystack(personel.sube_adi),
    haystack(personel.departman_adi)
  ].filter(Boolean);

  return tokens.every((token) => {
    const needle = foldForSearch(token);
    return fields.some((field) => field.includes(needle));
  });
}
