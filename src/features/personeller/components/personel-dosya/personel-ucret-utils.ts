import type { PersonelUcretKaydi, UcretDurum, UcretKaynak, UcretTuru } from "../../../../types/ucret";
import { formatIsoDateDetail } from "./personel-dosya-format-utils";

export const UCRET_KAYIT_YOK_MESAJI = "Bu personel için henüz ücret dönemi kaydı bulunmuyor.";
export const UCRET_GUNCEL_YOK_MESAJI = "Bugün için geçerli bir ücret kaydı bulunmuyor.";
export const UCRET_IPTAL_ONAY_MESAJI = "Bu ücret kaydını iptal etmek istediğinize emin misiniz?";

const UCRET_TURU_LABELS: Record<UcretTuru, string> = {
  BRUT: "Brüt",
  NET: "Net"
};

const UCRET_DURUM_LABELS: Record<UcretDurum, string> = {
  AKTIF: "Aktif",
  IPTAL: "İptal"
};

const UCRET_KAYNAK_LABELS: Record<UcretKaynak, string> = {
  MANUEL: "Manuel",
  PERSONEL_KAYDI_MIGRASYON: "Personel kaydı geçişi",
  SISTEM: "Sistem"
};

export function formatUcretTuruLabel(value: UcretTuru): string {
  return UCRET_TURU_LABELS[value] ?? value;
}

export function formatUcretDurumLabel(value: UcretDurum): string {
  return UCRET_DURUM_LABELS[value] ?? value;
}

export function formatUcretKaynakLabel(value: UcretKaynak): string {
  return UCRET_KAYNAK_LABELS[value] ?? value;
}

export function formatUcretTutar(tutar: number, paraBirimi = "TRY"): string {
  const formatted = new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(tutar);
  const currency = paraBirimi.trim().toUpperCase() || "TRY";
  return `${formatted} ${currency === "TRY" ? "TL" : currency}`;
}

export function formatUcretGecerlilikAraligi(record: PersonelUcretKaydi): string {
  const baslangic = formatIsoDateDetail(record.gecerlilik_baslangic);
  if (!record.gecerlilik_bitis) {
    return `${baslangic} — devam ediyor`;
  }
  return `${baslangic} — ${formatIsoDateDetail(record.gecerlilik_bitis)}`;
}

export function formatUcretOzeti(record: PersonelUcretKaydi): string {
  return `${formatUcretTutar(record.ucret_tutari, record.para_birimi)} (${formatUcretTuruLabel(record.ucret_turu)})`;
}

/** Backend zaten sıralı döner; demo/mock ve savunmacı kullanım için deterministik sıralama. */
export function sortUcretKayitlari(items: PersonelUcretKaydi[]): PersonelUcretKaydi[] {
  return [...items].sort((left, right) => {
    if (left.gecerlilik_baslangic !== right.gecerlilik_baslangic) {
      return left.gecerlilik_baslangic < right.gecerlilik_baslangic ? 1 : -1;
    }
    return (right.id ?? 0) - (left.id ?? 0);
  });
}

/** İptal yalnızca gerçek (id'li) AKTIF kayıtlar için sunulur. */
export function isUcretKaydiIptalEdilebilir(record: PersonelUcretKaydi): boolean {
  return record.durum === "AKTIF" && typeof record.id === "number";
}
