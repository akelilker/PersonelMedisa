export type Personel = {
  id: number;
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  aktif_durum: "AKTIF" | "PASIF";
  telefon?: string;
  dogum_tarihi?: string;
  sicil_no?: string;
};
