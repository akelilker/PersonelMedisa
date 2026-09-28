import type { RaporKolonu, RaporTipi } from "../../types/rapor";

export const RAPOR_COLUMN_CONTRACT: Record<RaporTipi, RaporKolonu[]> = {
  "personel-ozet": [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "sicil_no", label: "Sicil No" },
    { key: "aktif_durum", label: "Durum" },
    { key: "net_calisma_dakika", label: "Net Çalışma (dk)" },
    { key: "sgk_prim_gun", label: "SGK Prim Gün" }
  ],
  izin: [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "baslangic_tarihi", label: "Başlangıç" },
    { key: "bitis_tarihi", label: "Bitiş" },
    { key: "alt_tur", label: "İzin Türü" },
    { key: "ucretli_mi", label: "Ücretli mi" },
    { key: "state", label: "Durum" }
  ],
  devamsizlik: [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "baslangic_tarihi", label: "Başlangıç" },
    { key: "bitis_tarihi", label: "Bitiş" },
    { key: "alt_tur", label: "Alt Tür" },
    { key: "state", label: "Durum" }
  ],
  tesvik: [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "donem", label: "Dönem" },
    { key: "gun_sayisi", label: "Gün Sayısı" },
    { key: "toplam_tutar", label: "Toplam Tutar" },
    { key: "state", label: "Durum" }
  ],
  ceza: [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "donem", label: "Dönem" },
    { key: "tutar", label: "Tutar" },
    { key: "aciklama", label: "Açıklama" },
    { key: "state", label: "Durum" }
  ],
  "ekstra-prim": [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "donem", label: "Dönem" },
    { key: "tutar", label: "Tutar" },
    { key: "aciklama", label: "Açıklama" },
    { key: "state", label: "Durum" }
  ],
  "is-kazasi": [
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "baslangic_tarihi", label: "Başlangıç" },
    { key: "bitis_tarihi", label: "Bitiş" },
    { key: "aciklama", label: "Açıklama" },
    { key: "state", label: "Durum" }
  ],
  bildirim: [
    { key: "tarih", label: "Tarih" },
    { key: "departman_id", label: "Departman ID" },
    { key: "personel_id", label: "Personel ID" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "bildirim_turu", label: "Bildirim Türü" },
    { key: "aciklama", label: "Açıklama" },
    { key: "state", label: "Durum" }
  ],
  puantaj: [
    { key: "tarih", label: "Tarih" },
    { key: "personel_id", label: "Personel" },
    { key: "ad_soyad", label: "Ad Soyad" },
    { key: "sicil_no", label: "Sicil" },
    { key: "bolum", label: "Bölüm" },
    { key: "birim", label: "Birim" },
    { key: "giris_saati", label: "Giriş" },
    { key: "cikis_saati", label: "Çıkış" },
    { key: "net_calisma_dakika", label: "Net Çalışma (dk)" },
    { key: "gec_kalma_dakika", label: "Geç Kalma (dk)" },
    { key: "erken_cikis_dakika", label: "Erken Çıkış (dk)" },
    { key: "hareket_durumu", label: "Hareket" },
    { key: "dayanak", label: "İzin / Devamsızlık" }
  ]
};

export function getRaporColumns(raporTipi: RaporTipi): RaporKolonu[] {
  return RAPOR_COLUMN_CONTRACT[raporTipi];
}
