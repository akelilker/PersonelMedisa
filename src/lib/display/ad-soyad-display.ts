/**
 * Ad Soyad görünüm kuralının TEK sahibi (onaylı karar 2026-10-03):
 * Ad Türkçe Title Case + SOYAD BÜYÜK (ör. "Berat GÜRBÜZ").
 * Yalnız görünüm/arama katmanında kullanılır; canonical kayda yazılacak değeri
 * ASLA üretmez (bkz. `normalizeKullaniciAdSoyadForWrite`).
 */
export function formatNameToken(value: string) {
  if (!value) {
    return "";
  }

  return value
    .split("-")
    .map((part) => {
      if (!part) {
        return "";
      }

      return `${part.charAt(0).toLocaleUpperCase("tr-TR")}${part.slice(1).toLocaleLowerCase("tr-TR")}`;
    })
    .join("-");
}

/** Tek metin `ad_soyad`: son kelime soyad kabul edilir. */
export function formatAdSoyad(value: string) {
  const parts = value
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length === 0) {
    return "";
  }

  if (parts.length === 1) {
    return formatNameToken(parts[0]);
  }

  const soyad = parts.pop() ?? "";
  const adlar = parts.map(formatNameToken).join(" ");
  return `${adlar} ${soyad.toLocaleUpperCase("tr-TR")}`.trim();
}

/** Ayrı `ad` / `soyad` alanları (personel kaydı): çok kelimeli soyad da tamamen BÜYÜK. */
export function formatPersonelAdSoyadDisplay(personel: { ad?: string | null; soyad?: string | null }) {
  const adlar = String(personel.ad ?? "")
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .map(formatNameToken)
    .join(" ");
  const soyad = String(personel.soyad ?? "").trim().replace(/\s+/g, " ").toLocaleUpperCase("tr-TR");
  return [adlar, soyad].filter(Boolean).join(" ");
}
