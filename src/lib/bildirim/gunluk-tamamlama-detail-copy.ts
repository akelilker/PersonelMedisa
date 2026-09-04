import { formatIsoDateDetail } from "../../lib/display/iso-date-format";
import type { GunlukTamamlamaKayit } from "../../types/bildirim";

function trimText(value: string | null | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function positiveDakika(value: number | null | undefined): number | null {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) {
    return null;
  }
  return Math.trunc(value);
}

/** Person-line copy for submission detail categories (full DIGER text allowed). */
export function formatGunlukTamamlamaKayitLine(row: GunlukTamamlamaKayit): string {
  const name = trimText(row.ad_soyad) ?? "Personel";
  const tur = (row.bildirim_turu ?? "").toUpperCase();
  const dakika = positiveDakika(row.dakika);

  switch (tur) {
    case "GEC_GELDI":
      return dakika
        ? `${name} — ${dakika} Dakika Geç Geldi`
        : `${name} — Geç Geldi`;
    case "ERKEN_CIKTI":
      return dakika
        ? `${name} — ${dakika} Dakika Erken Çıktı`
        : `${name} — Erken Çıktı`;
    case "GELMEDI":
      return name;
    case "IZINLI":
      return `${name} — İzinli`;
    case "RAPORLU":
      return `${name} — Raporlu`;
    case "GOREVDE":
      return `${name} — Görevde`;
    case "DIGER": {
      const aciklama = trimText(row.aciklama);
      return aciklama ? `${name} — ${aciklama}` : name;
    }
    default:
      return name;
  }
}

export function formatGunlukTamamlamaTarih(tarih: string | null | undefined): string {
  return formatIsoDateDetail(tarih);
}
