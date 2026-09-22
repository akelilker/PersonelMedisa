/** Client-side kullanıcı listesi arama (Yönetim → Kullanıcılar). */

export const KULLANICI_SEARCH_MAX_LENGTH = 120;

export type KullaniciSearchFields = {
  displayName?: string | null;
  cardLabel?: string | null;
  adSoyad?: string | null;
  personelAdSoyad?: string | null;
  username?: string | null;
  roleLabel?: string | null;
  subeScopeLabel?: string | null;
  kullaniciTipiLabel?: string | null;
};

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

export function normalizeKullaniciSearchQuery(raw: string): string {
  const collapsed = String(raw ?? "")
    .replace(/\s+/gu, " ")
    .trim();
  if (collapsed.length <= KULLANICI_SEARCH_MAX_LENGTH) {
    return collapsed;
  }
  return collapsed.slice(0, KULLANICI_SEARCH_MAX_LENGTH).trim();
}

function foldForSearch(value: string): string {
  return value.replace(/[ıİşŞğĞüÜöÖçÇ]/g, (char) => TURKISH_FOLD[char] ?? char).toLowerCase();
}

function haystack(value: unknown): string {
  return foldForSearch(String(value ?? "").trim());
}

function tokenize(raw: string): string[] {
  const normalized = normalizeKullaniciSearchQuery(raw);
  if (!normalized) return [];
  return normalized.split(" ").filter(Boolean).slice(0, 6);
}

/**
 * Her token en az bir alanda bulunmalı (tokenlar arası AND, alanlar arası OR).
 */
export function matchesKullaniciSearch(fields: KullaniciSearchFields, rawQuery: string): boolean {
  const tokens = tokenize(rawQuery);
  if (tokens.length === 0) return true;

  const searchable = [
    fields.cardLabel,
    fields.displayName,
    fields.adSoyad,
    fields.personelAdSoyad,
    fields.username,
    fields.roleLabel,
    fields.subeScopeLabel,
    fields.kullaniciTipiLabel
  ]
    .map(haystack)
    .filter(Boolean);

  if (searchable.length === 0) return false;

  return tokens.every((token) => {
    const needle = foldForSearch(token);
    return searchable.some((field) => field.includes(needle));
  });
}
