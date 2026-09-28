import { formatIsoDateDetail } from "../../lib/display/iso-date-format";
import { hesaplaKidemYilAy } from "../../services/izin-hesap-motoru";
import type { MeIdentity } from "../../types/self-service";

export type PersonelSelfIdentityView = {
  adSoyad: string;
  /** İşe giriş · sicil · kıdem value line (from GET /me). */
  tenureLine: string;
  /** Present organization names only. Missing şube/bölüm/birim/görev are omitted. */
  organization: string[];
};

function present(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
}

function dash(value: string | null | undefined): string {
  const trimmed = present(value);
  return trimmed ?? "-";
}

function formatKidemCalisiyor(iseGiris: string): string {
  const kidem = hesaplaKidemYilAy(iseGiris);
  return `${kidem.yil} yıl ${kidem.ay} aydır çalışıyor`;
}

/**
 * Compact PERSONEL identity from canonical GET /me.
 * No placeholder organization, no sensitive identity fields beyond self-service summary.
 */
export function buildPersonelSelfIdentityView(me: MeIdentity): PersonelSelfIdentityView | null {
  const personel = me.personel;
  if (!personel) {
    const fallback = present(me.ad_soyad);
    return fallback
      ? { adSoyad: fallback, tenureLine: "- · - · -", organization: [] }
      : null;
  }
  const adSoyad =
    present(personel.ad_soyad) ??
    present(`${personel.ad} ${personel.soyad}`) ??
    present(me.ad_soyad);
  if (!adSoyad) {
    return null;
  }

  const iseGirisRaw = present(personel.ise_giris_tarihi);
  const iseGirisDisplay = iseGirisRaw ? formatIsoDateDetail(iseGirisRaw) : "-";
  const sicilDisplay = dash(personel.sicil_no);
  const kidemDisplay = iseGirisRaw ? formatKidemCalisiyor(iseGirisRaw) : "-";
  const tenureLine = `${iseGirisDisplay} · ${sicilDisplay} · ${kidemDisplay}`;

  const organization = [
    present(personel.sube_ad),
    present(personel.bolum_ad),
    present(personel.birim_ad),
    present(personel.gorev_ad)
  ].filter((item): item is string => item !== null);

  return { adSoyad, tenureLine, organization };
}
