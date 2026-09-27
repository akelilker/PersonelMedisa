import type { MeIdentity } from "../../types/self-service";

export type PersonelSelfIdentityView = {
  adSoyad: string;
  /** Present organization names only. Missing şube/bölüm/birim/görev are omitted. */
  organization: string[];
};

function present(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
}

/**
 * Compact PERSONEL identity from canonical GET /me.
 * No photo, no placeholder organization, no sensitive identity fields.
 */
export function buildPersonelSelfIdentityView(me: MeIdentity): PersonelSelfIdentityView | null {
  const personel = me.personel;
  if (!personel) {
    const fallback = present(me.ad_soyad);
    return fallback ? { adSoyad: fallback, organization: [] } : null;
  }
  const adSoyad =
    present(personel.ad_soyad) ??
    present(`${personel.ad} ${personel.soyad}`) ??
    present(me.ad_soyad);
  if (!adSoyad) {
    return null;
  }

  const organization = [
    present(personel.sube_ad),
    present(personel.bolum_ad),
    present(personel.birim_ad),
    present(personel.gorev_ad)
  ].filter((item): item is string => item !== null);

  return { adSoyad, organization };
}
