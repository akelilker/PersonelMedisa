/**
 * Demo/mock counterpart of the backend read model
 * (`api/src/Services/Organizasyon/SubeReadModel.php`).
 *
 * Only the mock API layer may call this: it stands in for the backend that
 * derives `tam_ad`. Product code consumes the `tam_ad` field it receives and
 * never rebuilds it, so the rule stays owned in one place per runtime.
 */

export function normalizeOrgName(value: string | null | undefined): string {
  return (value ?? "")
    .replace(/\s+/gu, " ")
    .trim()
    .toLocaleLowerCase("tr-TR");
}

export function deriveSubeTamAd(sirketAd: string | null | undefined, subeAd: string | null | undefined): string {
  const sube = (subeAd ?? "").replace(/\s+/gu, " ").trim();
  const sirket = (sirketAd ?? "").replace(/\s+/gu, " ").trim();

  if (!sirket) {
    return sube;
  }
  if (!sube) {
    return sirket;
  }
  // "Karyapı" under company "Karyapı" must not render as "Karyapı Karyapı".
  if (normalizeOrgName(sirket) === normalizeOrgName(sube)) {
    return sirket;
  }

  return `${sirket} ${sube}`;
}
