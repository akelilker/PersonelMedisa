/**
 * Onaylı karar (t869): API/form alan anahtarı → kullanıcıya görünen alan adı (Title Case).
 * Backend aynası: api/src/Http/FieldLabels.php (guard testi eşitliği kontrol eder).
 */
export const FIELD_LABELS: Readonly<Record<string, string>> = {
  aciklama: "Açıklama",
  ad: "Ad",
  ad_soyad: "Ad Soyad",
  aktif_durum: "Aktif Durum",
  alt_tur: "Alt Tür",
  ay: "Ay",
  bagli_amir_id: "Bağlı Amir",
  baslangic_tarihi: "Başlangıç Tarihi",
  baslangic_at: "Başlangıç Saati",
  belge_no: "Belge No.",
  belge_turu: "Belge Türü",
  bildirim_turu: "Bildirim Türü",
  birim_amiri_user_id: "Birim Amiri",
  birim_id: "Birim",
  birim_ids: "Birimler",
  bitis_tarihi: "Bitiş Tarihi",
  bitis_at: "Bitiş Saati",
  bolum_id: "Bölüm",
  bolum_ids: "Bölümler",
  calisma_lokasyonu_id: "Çalışma Lokasyonu",
  cinsiyet: "Cinsiyet",
  current_password: "Mevcut Şifre",
  dakika: "Dakika",
  departman_id: "Departman",
  dogum_tarihi: "Doğum Tarihi",
  dogum_yeri: "Doğum Yeri",
  donem: "Dönem",
  dosya_adi: "Dosya Adı",
  effective_date: "Geçerlilik Tarihi",
  etki_miktari: "Etki Miktarı",
  etkilenen_tarih: "Etkilenen Tarih",
  event_tarihi: "Olay Tarihi",
  gecerlilik_baslangic: "Geçerlilik Başlangıcı",
  gecerlilik_bitis: "Geçerlilik Bitişi",
  gerekce: "Gerekçe",
  gorev_id: "Görev / Unvan",
  gun_sayisi: "Gün Sayısı",
  gun_tipi: "Gün Tipi",
  hafta_baslangic: "Hafta Başlangıcı",
  hafta_bitis: "Hafta Bitişi",
  hedef_birim_id: "Hedef Birim",
  hedef_bolum_id: "Hedef Bölüm",
  iptal_nedeni: "İptal Nedeni",
  ise_giris_tarihi: "İşe Giriş Tarihi",
  izin_turu: "İzin Türü",
  kalem_turu: "Kalem Türü",
  kan_grubu: "Kan Grubu",
  karar_notu: "Karar Notu",
  karar_turu: "Karar Türü",
  kayit_tipi: "Kayıt Tipi",
  kisa_kod: "Kısa Kod",
  kod: "Kod",
  maas_tutari: "Maaş Tutarı",
  neden: "Neden",
  new_password: "Yeni Şifre",
  odeme_tipi: "Ödeme Tipi",
  personel_id: "Personel",
  personel_tipi_id: "Statü",
  pozisyon_id: "Pozisyon",
  referans_tarih: "Referans Tarihi",
  rol: "Rol",
  sgk_isveren_id: "SGK İşveren",
  sicil_no: "Sicil No.",
  sirket_id: "Şirket",
  soyad: "Soyad",
  sube_id: "Şube",
  sube_ids: "Şubeler",
  surec_turu: "Süreç Türü",
  talep_tarihi: "Talep Tarihi",
  tarih: "Tarih",
  tc_kimlik_no: "T.C. Kimlik No.",
  telefon: "Telefon",
  teslim_durumu: "Teslim Durumu",
  teslim_tarihi: "Teslim Tarihi",
  tutar: "Tutar",
  ucret_tipi_id: "Ücret Tipi",
  urun_turu: "Ürün Türü",
  username: "Kullanıcı Adı",
  yeni_sube_id: "Yeni Şube",
  yil: "Yıl",
  zimmet_durumu: "Zimmet Durumu"
};

function foldTr(text: string): string {
  return text
    .toLocaleLowerCase("tr-TR")
    .replace(/ç/g, "c").replace(/ğ/g, "g").replace(/ı/g, "i").replace(/i̇/g, "i").replace(/ö/g, "o")
    .replace(/ş/g, "s").replace(/ü/g, "u").replace(/[^a-z0-9]+/g, " ").trim();
}

const BY_FOLDED_LABEL = new Map<string, string>();
for (const [key, label] of Object.entries(FIELD_LABELS)) {
  BY_FOLDED_LABEL.set(foldTr(label), label);
  BY_FOLDED_LABEL.set(foldTr(key.replace(/_ids?$/, "").replace(/_/g, " ")), label);
  BY_FOLDED_LABEL.set(foldTr(key.replace(/_/g, " ")), label);
}
BY_FOLDED_LABEL.set("tc kimlik no", FIELD_LABELS.tc_kimlik_no!);
BY_FOLDED_LABEL.set("tc", FIELD_LABELS.tc_kimlik_no!);
BY_FOLDED_LABEL.set("t c kimlik no", FIELD_LABELS.tc_kimlik_no!);
BY_FOLDED_LABEL.set("sicil", FIELD_LABELS.sicil_no!);

/** Alan anahtarı veya ham metindeki alan adı → Title Case etiket; bilinmiyorsa null. */
export function fieldLabelOf(fieldOrText: string | null | undefined): string | null {
  if (!fieldOrText) return null;
  const direct = FIELD_LABELS[fieldOrText];
  if (direct) return direct;
  return BY_FOLDED_LABEL.get(foldTr(fieldOrText)) ?? null;
}

const RAW_FIELD_PATTERN =
  /^(.{1,40}?)\s+(?:alan[ıi]\s+)?(?:zorunludur|zorunlu|bo[sş] olamaz|bo[sş] b[ıi]rak[ıi]lamaz|ge[cç]ersiz(?:dir)?|ge[cç]erli de[gğ]il|girilmelidir|girilmeli|se[cç]ilmelidir|se[cç]ilmeli)\b/i;

/** "Gerekce zorunludur." → "Gerekçe" (yalnız bilinen alan adları). */
export function fieldLabelFromRaw(raw: string | null | undefined): string | null {
  if (!raw) return null;
  const match = RAW_FIELD_PATTERN.exec(raw.trim());
  if (!match?.[1]) return null;
  return fieldLabelOf(match[1].trim());
}

/** Etiketten alan anahtarı (ekrandaki kontrolü bulmak için). */
export function fieldKeyOfLabel(label: string): string | null {
  for (const [key, value] of Object.entries(FIELD_LABELS)) {
    if (value === label) return key;
  }
  return null;
}
