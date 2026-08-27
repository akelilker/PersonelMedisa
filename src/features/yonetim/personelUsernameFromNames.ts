/**
 * Personel kullanıcı adı üretimi — tek FE sahibi.
 * Kural: ilk ad (küçük) + soyadın ilk harfi (büyük), Türkçe → Latin.
 * Sicil numarası katılmaz; otomatik sayı eklenmez.
 */

const TURKISH_FOLD_LOWER: Record<string, string> = {
  ç: "c",
  Ç: "c",
  ğ: "g",
  Ğ: "g",
  ı: "i",
  İ: "i",
  I: "i",
  ö: "o",
  Ö: "o",
  ş: "s",
  Ş: "s",
  ü: "u",
  Ü: "u"
};

const TURKISH_FOLD_UPPER_INITIAL: Record<string, string> = {
  ç: "C",
  Ç: "C",
  ğ: "G",
  Ğ: "G",
  ı: "I",
  İ: "I",
  I: "I",
  i: "I",
  ö: "O",
  Ö: "O",
  ş: "S",
  Ş: "S",
  ü: "U",
  Ü: "U"
};

function foldToLowerAscii(value: string): string {
  let out = "";
  for (const ch of value) {
    out += TURKISH_FOLD_LOWER[ch] ?? ch;
  }
  return out.toLowerCase().replace(/[^a-z0-9]/g, "");
}

function foldSurnameInitial(value: string): string {
  const trimmed = value.trim();
  if (!trimmed) {
    return "";
  }
  const first = trimmed[0] ?? "";
  const mapped = TURKISH_FOLD_UPPER_INITIAL[first] ?? first.toUpperCase();
  return /^[A-Z]$/.test(mapped) ? mapped : "";
}

/** @returns önerilen kullanıcı adı veya boş string (ad/soyad yetersiz) */
export function buildPersonelUsernameFromNames(
  ad: string | null | undefined,
  soyad: string | null | undefined
): string {
  const adTrim = String(ad ?? "").trim();
  const soyadTrim = String(soyad ?? "").trim();
  if (!adTrim || !soyadTrim) {
    return "";
  }
  const firstAd = adTrim.split(/\s+/)[0] ?? "";
  const namePart = foldToLowerAscii(firstAd);
  const initial = foldSurnameInitial(soyadTrim);
  if (!namePart || !initial) {
    return "";
  }
  return `${namePart}${initial}`;
}
