import { formatIsoDateDetail } from "../../lib/display/iso-date-format";
import { hesaplaKidemYilAy } from "../../services/izin-hesap-motoru";
import type { MeIdentity } from "../../types/self-service";

export type PersonelSelfIdentityView = {
  adSoyad: string;
  /** Sicil no (from GET /me), "-" when missing. */
  sicil: string;
  /** İşe giriş tarihi (tr-TR), "-" when missing. */
  iseGiris: string;
  /** Kıdem "X yıl Y ay", "-" when missing. */
  calismaSuresi: string;
  /** Yalnızca şube adı + " - " + görev adı. Değer yoksa o parça yazılmaz; ikisi de yoksa null. */
  subeGorev: string | null;
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
  return `${kidem.yil} yıl ${kidem.ay} ay`;
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
      ? { adSoyad: fallback, sicil: "-", iseGiris: "-", calismaSuresi: "-", subeGorev: null }
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
  const iseGiris = iseGirisRaw ? formatIsoDateDetail(iseGirisRaw) : "-";
  const sicil = dash(personel.sicil_no);
  const calismaSuresi = iseGirisRaw ? formatKidemCalisiyor(iseGirisRaw) : "-";

  const sube = present(personel.sube_ad);
  const gorev = present(personel.gorev_ad);
  const subeGorev = sube && gorev ? `${sube} - ${gorev}` : sube ?? gorev;

  return { adSoyad, sicil, iseGiris, calismaSuresi, subeGorev };
}
