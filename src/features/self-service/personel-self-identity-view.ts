import { formatIsoDateDetail } from "../../lib/display/iso-date-format";
import { hesaplaKidemYilAy } from "../../services/izin-hesap-motoru";
import type { MeIdentity } from "../../types/self-service";

export type PersonelSelfIdentityView = {
  adSoyad: string;
  /** Doğum tarihi gg.aa.yyyy, "-" when missing. */
  dogumTarihi: string;
  /** "-" until a canonical cinsiyet value exists. Never invented. */
  cinsiyet: string;
  telefon: string;
  /** Sicil no (from GET /me), "-" when missing. */
  sicil: string;
  tcKimlikNo: string;
  kanGrubu: string;
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

function emptyIdentityFacts(): Omit<PersonelSelfIdentityView, "adSoyad" | "subeGorev"> {
  return {
    dogumTarihi: "-",
    cinsiyet: "-",
    telefon: "-",
    sicil: "-",
    tcKimlikNo: "-",
    kanGrubu: "-",
    iseGiris: "-",
    calismaSuresi: "-"
  };
}

/**
 * Compact PERSONEL identity from canonical GET /me.
 * Missing fields stay "-". No placeholder organization and no invented identity values.
 */
export function buildPersonelSelfIdentityView(me: MeIdentity): PersonelSelfIdentityView | null {
  const personel = me.personel;
  if (!personel) {
    const fallback = present(me.ad_soyad);
    return fallback ? { adSoyad: fallback, ...emptyIdentityFacts(), subeGorev: null } : null;
  }
  const adSoyad =
    present(personel.ad_soyad) ??
    present(`${personel.ad} ${personel.soyad}`) ??
    present(me.ad_soyad);
  if (!adSoyad) {
    return null;
  }

  const iseGirisRaw = present(personel.ise_giris_tarihi);
  const dogumRaw = present(personel.dogum_tarihi);
  const iseGiris = iseGirisRaw ? formatIsoDateDetail(iseGirisRaw) : "-";
  const dogumTarihi = dogumRaw ? formatIsoDateDetail(dogumRaw) : "-";
  const sicil = dash(personel.sicil_no);
  const calismaSuresi = iseGirisRaw ? formatKidemCalisiyor(iseGirisRaw) : "-";

  const sube = present(personel.sube_ad);
  const gorev = present(personel.gorev_ad);
  const subeGorev = sube && gorev ? `${sube} - ${gorev}` : sube ?? gorev;

  return {
    adSoyad,
    dogumTarihi,
    cinsiyet: dash(personel.cinsiyet),
    telefon: dash(personel.telefon),
    sicil,
    tcKimlikNo: dash(personel.tc_kimlik_no),
    kanGrubu: dash(personel.kan_grubu),
    iseGiris,
    calismaSuresi,
    subeGorev
  };
}
