import type { Personel } from "../../types/personel";

/**
 * A pozisyon has no validity date of its own; the date on screen is the start
 * date of the personel's görev/pozisyon assignment (`Göreve Başlama Tarihi`).
 *
 * A personel with no görev and no pozisyon yet has never been assigned, so the
 * first assignment starts on the hire date. Every later change starts today.
 */
export function isFirstGorevAtamasi(personel: Personel | null): boolean {
  if (!personel) {
    return false;
  }

  return personel.gorev_id == null && personel.pozisyon_id == null;
}

export function resolveGoreveBaslamaTarihiDefault(personel: Personel | null): string {
  if (isFirstGorevAtamasi(personel)) {
    const iseGiris = (personel?.ise_giris_tarihi ?? "").trim();
    if (iseGiris) {
      return iseGiris.slice(0, 10);
    }
  }

  return new Date().toISOString().slice(0, 10);
}
