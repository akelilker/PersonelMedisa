import { formatIsoDateDetail } from "../../lib/display/iso-date-format";
import {
  hesaplaKidemYilAy,
  hesaplaIlkYillikIzinHakTarihi,
  yillikIzinHakkiBasladiMi
} from "../../services/izin-hesap-motoru";
import type { YillikIzinBakiye } from "../../types/yillik-izin-hak-duzeltme";

export type SelfServiceYillikIzinView = {
  hakBasladi: boolean;
  rowText: string;
  pendingMessage: string | null;
  iseGirisLabel: string;
  kidemLabel: string;
  toplamLabel: string;
  kullanilanLabel: string;
  kalanLabel: string;
};

function formatGunValue(value: number | null): string {
  return value === null ? "Kesinleştirilemedi" : `${value} gün`;
}

function buildPendingMessage(iseGirisTarihi: string): string | null {
  const ilkHak = hesaplaIlkYillikIzinHakTarihi(iseGirisTarihi);
  if (!ilkHak) {
    return null;
  }
  const formatted = formatIsoDateDetail(ilkHak);
  if (formatted === "-") {
    return null;
  }
  return `İzin hakkınız ${formatted} tarihinde başlar`;
}

export function buildSelfServiceYillikIzinView(bakiye: YillikIzinBakiye): SelfServiceYillikIzinView | null {
  const iseGiris = bakiye.ise_giris_tarihi?.trim();
  if (!iseGiris) {
    return null;
  }

  const referans = bakiye.referans_tarih ?? undefined;
  const hakBasladi = yillikIzinHakkiBasladiMi(iseGiris, referans);
  const pendingMessage = buildPendingMessage(iseGiris);
  const kidem = hesaplaKidemYilAy(iseGiris, referans);
  const kidemLabel = `${kidem.yil} yıl / ${kidem.ay} ay`;

  if (!hakBasladi) {
    const message = pendingMessage ?? "İzin hakkınız henüz başlamadı";
    return {
      hakBasladi: false,
      rowText: message,
      pendingMessage: message,
      iseGirisLabel: formatIsoDateDetail(iseGiris),
      kidemLabel,
      toplamLabel: "0 gün",
      kullanilanLabel: formatGunValue(bakiye.kullanilan_gun),
      kalanLabel: "0 gün"
    };
  }

  const kalanText = bakiye.kalan_gun === null ? "Kesinleştirilemedi" : `${bakiye.kalan_gun} gün`;

  return {
    hakBasladi: true,
    rowText: `Kalan izin: ${kalanText}`,
    pendingMessage: null,
    iseGirisLabel: formatIsoDateDetail(iseGiris),
    kidemLabel,
    toplamLabel: formatGunValue(bakiye.efektif_hak_gun),
    kullanilanLabel: formatGunValue(bakiye.kullanilan_gun),
    kalanLabel: formatGunValue(bakiye.kalan_gun)
  };
}
