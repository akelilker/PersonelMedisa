import { formatFinansKalemTuruLabel } from "../../../../lib/display/enum-display";
import type { FinansKalem } from "../../../../types/finans";
import { formatDetailValue } from "./personel-dosya-format-utils";

export const FINANS_ADAY_DONEM_YOK_MESAJI =
  "Dönem bilgisi olmadığı için finans adayları gösterilemiyor.";
export const FINANS_ADAY_KAYIT_YOK_MESAJI = "Bu dönem için aktif finans kaydı görünmüyor.";
export const FINANS_ADAY_YETKI_YOK_MESAJI = "Finans kayıtlarını görüntüleme yetkiniz yok.";

const EK_ODEME_KALEM_TURLERI = new Set([
  "PRIM",
  "EKSTRA_PRIM",
  "BONUS",
  "IKRAMIYE",
  "TESVIK",
  "MESAI",
  "MAAS"
]);

const KESINTI_KALEM_TURLERI = new Set(["CEZA", "DIGER_KESINTI", "BES"]);

function normalizeKalemTuru(value: string) {
  return value.trim().toUpperCase();
}

export function isAktifFinansKaydi(item: FinansKalem) {
  const state = item.state?.trim().toUpperCase();
  return !state || state === "AKTIF";
}

export function sortFinansKayitlari(items: FinansKalem[]) {
  return [...items].sort((left, right) => {
    const kalemCompare = left.kalem_turu.localeCompare(right.kalem_turu, "tr");
    if (kalemCompare !== 0) {
      return kalemCompare;
    }
    return right.id - left.id;
  });
}

export function formatFinansKayitTutar(tutar: number) {
  return `${new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(tutar)} TL`;
}

export function formatFinansKayitAdayRolu(kalemTuru: string): string {
  const normalized = normalizeKalemTuru(kalemTuru);

  if (normalized === "AVANS") {
    return "Maaştan mahsup edilecek aday";
  }

  if (EK_ODEME_KALEM_TURLERI.has(normalized)) {
    return "Ek ödeme adayı";
  }

  if (KESINTI_KALEM_TURLERI.has(normalized)) {
    return "Bordroda dikkate alınacak kesinti adayı";
  }

  return "Bordroda dikkate alınacak finans kaydı";
}

export function formatFinansKayitSatirOzeti(item: FinansKalem): string {
  const parts = [
    formatFinansKalemTuruLabel(item.kalem_turu),
    formatFinansKayitTutar(item.tutar),
    formatFinansKayitAdayRolu(item.kalem_turu)
  ];

  if (typeof item.gun_sayisi === "number" && item.gun_sayisi > 0) {
    parts.push(`${item.gun_sayisi} gün`);
  }

  if (item.aciklama) {
    parts.push(formatDetailValue(item.aciklama));
  }

  return parts.join(" — ");
}
