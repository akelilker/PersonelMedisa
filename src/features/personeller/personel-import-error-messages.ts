import { ApiRequestError } from "../../api/api-client";

const IMPORT_ERROR_MESSAGES: Record<string, string> = {
  SCHEMA_NOT_READY: "Sistem bu işlem için henüz hazır değil.",
  PERSONEL_IMPORT_DOSYA_GECERSIZ: "Dosya içeriği okunamadı.",
  PERSONEL_IMPORT_DOSYA_BOYUTU: "Dosya boyutu izin verilen sınırı aşıyor.",
  PERSONEL_IMPORT_EKSIK_ZORUNLU_KOLON: "Zorunlu kolon eksik.",
  PERSONEL_IMPORT_BILINMEYEN_KOLON: "Dosyada tanınmayan bir kolon bulundu.",
  PERSONEL_IMPORT_SATIR_KOLON_UYUMSUZ: "Satırdaki kolon sayısı beklenen yapıyla eşleşmiyor.",
  PERSONEL_IMPORT_SATIR_SINIRI: "Dosyada izin verilen satır sayısı aşılıyor.",
  PERSONEL_IMPORT_GECERSIZ_TC: "T.C. Kimlik No geçersiz.",
  PERSONEL_IMPORT_EKSIK_ALAN: "Zorunlu bilgi eksik.",
  PERSONEL_IMPORT_EKSIK_TELEFON: "İç personelin telefonu daha sonra Kayıt ve Süreç üzerinden tamamlanabilir.",
  PERSONEL_IMPORT_GECERSIZ_TARIH: "Tarih bilgisi geçersiz.",
  PERSONEL_IMPORT_REFERANS_BULUNAMADI: "Referans bilgisi bulunamadı.",
  PERSONEL_IMPORT_REFERANS_BELIRSIZ: "Referans eşleşmesi belirsiz.",
  PERSONEL_IMPORT_DOSYA_ICI_DUPLICATE_TC: "Dosyada yinelenen T.C. Kimlik No bulundu.",
  PERSONEL_IMPORT_DOSYA_ICI_DUPLICATE_SICIL: "Dosyada yinelenen sicil bulundu.",
  PERSONEL_IMPORT_TC_MEVCUT: "Bu T.C. Kimlik No ile kayıt zaten mevcut.",
  PERSONEL_IMPORT_SICIL_MEVCUT: "Bu sicil ile kayıt zaten mevcut.",
  PERSONEL_IMPORT_SUBE_SCOPE_IHLALI: "Bu şube için işlem yetkiniz yok.",
  PERSONEL_IMPORT_ALREADY_EXISTS: "Bu kayıt zaten mevcut.",
  PERSONEL_IMPORT_NOT_APPLICABLE: "Aktarım için tüm satırların geçerli olması gerekir.",
  PERSONEL_IMPORT_MANIFEST_CHANGED: "Doğrulama dosyası güncel değil; yeniden doğrulama yapın.",
  PERSONEL_IMPORT_REFERENCE_CHANGED: "Referans veya kaynak bilgisi değişmiş; yeniden doğrulama yapın.",
  PERSONEL_IMPORT_TRANSACTION_FAILED: "Aktarım işlemi tamamlanamadı.",
  PERSONEL_IMPORT_CONFIRMATION_REQUIRED: "Aktarım onayı zorunludur.",
  PERSONEL_IMPORT_IDEMPOTENCY_KEY_INVALID: "Aktarım anahtarı geçersiz.",
  PERSONEL_IMPORT_MANIFEST_REQUIRED: "Doğrulama özeti zorunludur.",
  PERSONEL_IMPORT_SOURCE_REQUIRED: "Kaynak özeti geçersiz.",
  PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT: "Aynı aktarım anahtarı farklı bir kaynakla kullanılmış.",
  PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR: "Ücret ve bordro alanları bu aktarımda kullanılamaz.",
  DIS_KAYNAK_SGK_ISVEREN_YASAK: "Dış kaynak personeline PersonelMedisa SGK işvereni atanamaz.",
  PERSONEL_OPERASYON_KAPSAM_DISI: "Bu dış kaynak kaydı operasyonel işlemlerde kullanılamaz."
};

export function importErrorMessage(code: string): string {
  return IMPORT_ERROR_MESSAGES[code] ?? "Bilgiler doğrulanamadı.";
}

export function visibleImportError(error: unknown, fallback: string): string {
  if (error instanceof ApiRequestError && error.code) {
    return importErrorMessage(error.code);
  }
  return error instanceof Error ? error.message : fallback;
}
