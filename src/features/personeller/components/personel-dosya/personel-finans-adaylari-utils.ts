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
  "MESAI"
]);

const EK_ODEME_TOPLAM_KALEM_TURLERI = new Set([
  "PRIM",
  "EKSTRA_PRIM",
  "BONUS",
  "IKRAMIYE",
  "TESVIK",
  "MESAI"
]);

const KESINTI_KALEM_TURLERI = new Set(["CEZA", "DIGER_KESINTI", "BES"]);

export type FinansAdayGrubu = "mahsup" | "kesinti" | "ek_odeme";

export type FinansAdayToplamlari = {
  mahsupAdayTutari: number;
  kesintiAdayTutari: number;
  ekOdemeAdayTutari: number;
  dahilEdilmeyenKayitSayisi: number;
};

function normalizeKalemTuru(value: string) {
  return value.trim().toUpperCase();
}

export function isAktifFinansKaydi(item: FinansKalem) {
  const state = item.state?.trim().toUpperCase();
  return !state || state === "AKTIF";
}

export function getFinansAdayGrubu(kalemTuru: string): FinansAdayGrubu | null {
  const normalized = normalizeKalemTuru(kalemTuru);

  if (normalized === "AVANS") {
    return "mahsup";
  }

  if (normalized === "MAAS") {
    return null;
  }

  if (KESINTI_KALEM_TURLERI.has(normalized)) {
    return "kesinti";
  }

  if (EK_ODEME_TOPLAM_KALEM_TURLERI.has(normalized)) {
    return "ek_odeme";
  }

  return null;
}

export function computeFinansAdayToplamlari(kayitlar: FinansKalem[]): FinansAdayToplamlari {
  let mahsupAdayTutari = 0;
  let kesintiAdayTutari = 0;
  let ekOdemeAdayTutari = 0;
  let dahilEdilmeyenKayitSayisi = 0;

  for (const item of kayitlar) {
    if (!isAktifFinansKaydi(item) || item.tutar <= 0) {
      dahilEdilmeyenKayitSayisi += 1;
      continue;
    }

    const grup = getFinansAdayGrubu(item.kalem_turu);
    if (grup === "mahsup") {
      mahsupAdayTutari += item.tutar;
      continue;
    }
    if (grup === "kesinti") {
      kesintiAdayTutari += item.tutar;
      continue;
    }
    if (grup === "ek_odeme") {
      ekOdemeAdayTutari += item.tutar;
      continue;
    }

    dahilEdilmeyenKayitSayisi += 1;
  }

  return {
    mahsupAdayTutari,
    kesintiAdayTutari,
    ekOdemeAdayTutari,
    dahilEdilmeyenKayitSayisi
  };
}

export function hasFinansAdayToplami(toplamlar: FinansAdayToplamlari): boolean {
  return (
    toplamlar.mahsupAdayTutari > 0 ||
    toplamlar.kesintiAdayTutari > 0 ||
    toplamlar.ekOdemeAdayTutari > 0
  );
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

  if (normalized === "MAAS") {
    return "Toplam dışı finans kaydı";
  }

  if (EK_ODEME_KALEM_TURLERI.has(normalized)) {
    return "Ek ödeme adayı";
  }

  if (KESINTI_KALEM_TURLERI.has(normalized)) {
    return "Kesinti adayı";
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
