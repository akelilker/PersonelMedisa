import { FIELD_LABELS, fieldKeyOfLabel, fieldLabelFromRaw, fieldLabelOf } from "./field-labels";
import { highlightFieldErrors } from "../forms/field-error-highlight";
/**
 * Onaylı karar (t865/HATA-KARAR.md): kullanıcıya görünen tüm hata metinleri bu
 * tablodan gelir. Ham sunucu metni, hata kodu, API yolu veya teknik terim gösterilmez.
 * Her kelimenin ilk harfi büyük (Türkçe: i→İ, ı→I); kısaltmalar (T.C., SGK) korunur.
 */

export type UserErrorKategori =
  | "oturum" | "giris_hatali" | "sifre_degistir" | "sifre_kurali" | "yetki" | "kendi_talebi"
  | "kapsam" | "bulunamadi" | "zorunlu" | "gecersiz" | "tarih" | "pazartesi" | "mukerrer"
  | "cakisma" | "eszamanli" | "durum_uygun_degil" | "onay_beklemiyor" | "onceki_onay"
  | "donem_kapali" | "donem_muhursuz" | "donem_yeniden_acik" | "maas_aktif_hesap"
  | "hesap_verisi_degisti" | "eksik_veri" | "pasif" | "dosya" | "toplu_aktarim"
  | "altyapi" | "islem_tamamlanamadi" | "yanit_yok";

export type UserErrorSatiri = { mesaj: string; aciklama?: string };

export function toTitleCaseTr(text: string): string {
  return text
    .split(/(\s+)/)
    .map((word) => {
      if (!word.trim() || /^[\d[(]/.test(word)) return word;
      if (/^[A-ZÇĞİÖŞÜ.]{2,}[.,:;!?]?$/.test(word) || /^T\.C\./.test(word)) return word;
      const first = word.charAt(0);
      return first.toLocaleUpperCase("tr-TR") + word.slice(1);
    })
    .join("");
}

const T = toTitleCaseTr;

export const USER_ERROR_TABLOSU: Record<UserErrorKategori, UserErrorSatiri> = {
  oturum: { mesaj: T("Oturumunuz sona erdi. Lütfen yeniden giriş yapın.") },
  giris_hatali: { mesaj: T("Kullanıcı adı veya şifre hatalı.") },
  sifre_degistir: { mesaj: T("Devam etmek için önce şifrenizi değiştirin.") },
  sifre_kurali: { mesaj: T("Yeni şifre en az 8 karakter olmalı ve iki alana aynı girilmelidir.") },
  yetki: { mesaj: T("Bu işlem için yetkiniz yok."), aciklama: T("Yetki gerekiyorsa sistem yöneticinize başvurun.") },
  kendi_talebi: { mesaj: T("Kendi talebinizi onaylayamaz veya reddedemezsiniz."), aciklama: T("Talep başka bir yetkili tarafından onaylanmalıdır.") },
  kapsam: { mesaj: T("Bu şube veya personel için işlem yapamazsınız."), aciklama: T("Yalnızca size atanmış şubelerde işlem yapabilirsiniz.") },
  bulunamadi: { mesaj: T("Kayıt bulunamadı. Sayfayı yenileyip tekrar deneyin.") },
  zorunlu: { mesaj: T("Bu alan boş bırakılamaz.") },
  gecersiz: { mesaj: T("Bu alan geçerli değil. Lütfen kontrol edin.") },
  tarih: { mesaj: T("Tarih geçerli değil. Bitiş tarihi başlangıçtan önce olamaz.") },
  pazartesi: { mesaj: T("Hafta başlangıcı olarak bir Pazartesi seçin.") },
  mukerrer: { mesaj: T("Bu kayıt zaten var.") },
  cakisma: { mesaj: T("Bu tarih aralığı mevcut bir kayıtla çakışıyor."), aciklama: T("Önce mevcut kaydı düzenleyin veya kapatın.") },
  eszamanli: { mesaj: T("Bu kayıt siz işlem yaparken değişti. Sayfayı yenileyip tekrar deneyin.") },
  durum_uygun_degil: { mesaj: T("Bu kayıt mevcut durumunda bu işleme uygun değil."), aciklama: T("Yalnızca uygun durumdaki kayıtlarda bu işlem yapılabilir.") },
  onay_beklemiyor: { mesaj: T("Bu talep artık onay beklemiyor.") },
  onceki_onay: { mesaj: T("Önce bekleyen bölüm onaylarının tamamlanması gerekiyor.") },
  donem_kapali: { mesaj: T("Bu dönem kapatıldığı için değişiklik yapılamaz."), aciklama: T("Düzeltme için Revizyon Merkezi'nden talep açın.") },
  donem_muhursuz: { mesaj: T("Bu işlem için önce dönemin kapatılması gerekiyor.") },
  donem_yeniden_acik: { mesaj: T("Dönem yeniden açık olduğu için maaş hesaplama şu an yapılamaz."), aciklama: T("Dönem tekrar kapatıldıktan sonra deneyin.") },
  maas_aktif_hesap: { mesaj: T("Bu dönem için geçerli bir maaş hesabı var. Değişiklik için önce onu iptal edin."), aciklama: T("İptal sonrası hesap yeniden oluşturulabilir; eski hesap silinmez.") },
  hesap_verisi_degisti: { mesaj: T("Hesaplama verileri değişti. Kontrolü yeniden çalıştırın.") },
  eksik_veri: { mesaj: T("Hesaplama için eksik bilgi var."), aciklama: T("Eksik bilgileri Kayıt ve Süreç ekranından tamamlayın.") },
  pasif: { mesaj: T("Bu personel veya hesap aktif değil.") },
  dosya: { mesaj: T("Dosya okunamadı. Dosya türünü ve içeriğini kontrol edin."), aciklama: T("Yalnızca şablona uygun dosyalar kabul edilir.") },
  toplu_aktarim: { mesaj: T("Bazı satırlarda hata var. Hatalı satırları düzeltip yeniden kontrol edin.") },
  altyapi: { mesaj: T("Bu özellik şu anda kullanılamıyor. Lütfen sistem yöneticisine bildirin.") },
  islem_tamamlanamadi: { mesaj: T("İşlem tamamlanamadı, hiçbir değişiklik kaydedilmedi. Tekrar deneyin."), aciklama: T("Sorun sürerse sistem yöneticisine bildirin.") },
  yanit_yok: { mesaj: T("Bilgiler yüklenemedi. Sayfayı yenileyip tekrar deneyin.") }
};

const KURALLAR: [UserErrorKategori, RegExp][] = [
  ["altyapi", /SCHEMA|[sş]emas[ıi]|haz[ıi]r de[gğ]il|NOT_READY|migration|altyap/i],
  ["oturum", /UNAUTHORIZED|SESSION_EXPIRED|oturum gerekli|oturum(unuz)? (sona|sonlan|d[uü][sş])|CSRF/i],
  ["giris_hatali", /INVALID_CREDENTIALS|kullan[ıi]c[ıi] ad[ıi] veya [sş]ifre|mevcut [sş]ifre hatal/i],
  ["sifre_degistir", /PASSWORD_CHANGE_REQUIRED|[sş]ifre de[gğ]i[sş]tirme zorunlu/i],
  ["sifre_kurali", /[sş]ifre/i],
  ["kendi_talebi", /SELF_APPROVAL|kendi talebini|kendisini/i],
  ["onceki_onay", /onaylar[ıi] tamamlanmadan|[oö]nceki onay/i],
  ["onay_beklemiyor", /NOT_PENDING|bekliyor de[gğ]il|bekleyen durumda de[gğ]il|yaln[ıi]z bekleyen/i],
  ["donem_yeniden_acik", /reopen oturum|yeniden a[cç]ma oturum|REOPEN_ACTIVE/i],
  ["donem_muhursuz", /PERIOD_NOT_SEALED|m[uü]h[uü]rlenmemi[sş]|SEALED de[gğ]il/i],
  ["maas_aktif_hesap", /aktif (maa[sş] )?snapshot|ACTIVE_SNAPSHOT|aktif sabit kay/i],
  ["hesap_verisi_degisti", /hash g[uü]ncel de[gğ]il|preflight.*g[uü]ncel|kaynak(lar)? de[gğ]i[sş]|SOURCE_CHANGED|STALE_PREFLIGHT/i],
  ["eszamanli", /STALE|stale|yar[ıi][sş]|g[uü]ncel de[gğ]il|CONCURREN|LOCK_TIMEOUT|kilidi al[ıi]namad/i],
  ["donem_kapali", /PERIOD_(LOCKED|SEALED|CLOSED)|WEEK_CLOSED|LOCKED|kapal[ıi] (hafta|d[oö]nem)|d[oö]nem (kilitli|kapal|m[uü]h[uü]rl)|m[uü]h[uü]rl[uü]|kapan[ıi][sş] yap[ıi]lm/i],
  ["kapsam", /SCOPE|kapsam[ıi] d[ıi][sş]|[sş]ube.*(eri[sş]im|d[ıi][sş][ıi]nda|yetki)|BRANCH_/i],
  ["yetki", /FORBIDDEN|DENIED|yetki|izniniz|yaln[ıi]z GENEL_YONETICI/i],
  ["bulunamadi", /NOT_FOUND|bulunamad/i],
  ["pazartesi", /pazartesi/i],
  ["cakisma", /[cç]ak[ıi][sş]an|[cç]ak[ıi][sş]mas|OVERLAP/i],
  ["mukerrer", /DUPLICATE|ALREADY|zaten|mevcut\.?$|CONFLICT/i],
  ["pasif", /PASSIVE|INACTIVE|pasif|aktif de[gğ]il/i],
  ["toplu_aktarim", /IMPORT|import|dry.?run|satır|satir|commit/i],
  ["dosya", /FILE|dosya|CSV|uzant|y[uü]kle(nen|me)|MIME/i],
  ["eksik_veri", /eksik|olmadan .*yap[ıi]lamaz|tamamlan(mam|mad)/i],
  ["zorunlu", /REQUIRED|zorunlu|gerekli|bo[sş] (olamaz|b[ıi]rak)|giriniz|girin\b|se[cç]in(iz)?\b|se[cç]ilmeli/i],
  ["tarih", /DATE|tarih|ba[sş]lang[ıi][cç]|biti[sş]|YYYY|yyyy/i],
  ["durum_uygun_degil", /yaln[ıi]z (taslak|aktif|onayl)|iptal edilmi[sş]|ba[sş]lam[ıi][sş]|INVALID_STATE|STATUS|durum(u|da)|_HAS_[A-Z]+|silin(e)?mez|silme i[sş]lemi yap[ıi]lamaz|ba[gğ]l[ıi] (personel|[sş]ube|kay[ıi]t)[a-zçğıöşü ]* var/i],
  ["gecersiz", /INVALID|VALIDATION|ge[cç]ersiz|format|bi[cç]im|say[ıi]sal|karakter|en az|en fazla|olmal[ıi]/i],
  ["yanit_yok", /yan[ıi]t|beklenen|y[uü]klenemedi|okunamad|al[ıi]namad|getirilemedi|\/api\/|GET |POST |fetch|network|a[gğ] hatas/i]
];

const TABLO_METINLERI = new Set(
  Object.values(USER_ERROR_TABLOSU).flatMap((s) => [s.mesaj, s.aciklama ? `${s.mesaj} ${s.aciklama}` : s.mesaj])
);

export function classifyUserError(raw: string | null | undefined, code?: string | null, status?: number): UserErrorKategori {
  const text = `${code ?? ""} ${raw ?? ""} ${(raw ?? "").toLocaleLowerCase("tr-TR")}`;
  if (status === 401) return "oturum";
  for (const [kategori, kural] of KURALLAR) {
    if (kural.test(text)) return kategori;
  }
  if (status === 403) return "yetki";
  if (status === 404) return "bulunamadi";
  if (status === 409) return "eszamanli";
  if (status && status >= 500) return "islem_tamamlanamadi";
  if (status === 422 || status === 400) return "gecersiz";
  return "islem_tamamlanamadi";
}

const ALAN_ZORUNLU_SONEK = " Boş Bırakılamaz.";
const ALAN_GECERSIZ_SONEK = " Geçerli Değil. Lütfen Kontrol Edin.";

/** "T.C. Kimlik No. Boş Bırakılamaz." gibi alan adlı tablo metni mi? */
export function isFieldErrorText(text: string): boolean {
  const t = text.trim();
  for (const sonek of [ALAN_ZORUNLU_SONEK, ALAN_GECERSIZ_SONEK]) {
    if (t.endsWith(sonek)) {
      const label = t.slice(0, -sonek.length);
      if (label === "Bu Alan" || Object.values(FIELD_LABELS).includes(label)) return true;
    }
  }
  return false;
}

/**
 * Kullanıcıya gösterilecek tek satır (mesaj + varsa açıklama). Zaten tablo metniyse aynen döner.
 * t869: zorunlu/geçersiz alan hatalarında alan adı yazılır ("T.C. Kimlik No. Boş Bırakılamaz.")
 * ve ekrandaki ilgili alan kırmızı çerçeveyle işaretlenip odaklanır.
 */
export function userErrorText(
  raw: string | null | undefined,
  code?: string | null,
  status?: number,
  field?: string | null
): string {
  if (typeof raw === "string" && (TABLO_METINLERI.has(raw.trim()) || isFieldErrorText(raw))) return raw.trim();
  const kategori = classifyUserError(raw, code, status);
  if (kategori === "zorunlu" || kategori === "gecersiz") {
    const label = fieldLabelOf(field) ?? fieldLabelFromRaw(raw);
    if (label) {
      const target = field && FIELD_LABELS[field] ? field : (fieldKeyOfLabel(label) ?? label);
      void highlightFieldErrors([target]);
      return `${label}${kategori === "zorunlu" ? ALAN_ZORUNLU_SONEK : ALAN_GECERSIZ_SONEK}`;
    }
  }
  const satir = USER_ERROR_TABLOSU[kategori];
  return satir.aciklama ? `${satir.mesaj} ${satir.aciklama}` : satir.mesaj;
}

export function isUserErrorTableText(text: string): boolean {
  return TABLO_METINLERI.has(text.trim());
}
