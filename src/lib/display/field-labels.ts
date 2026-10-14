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
  baslik: "Başlık",
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
  yayin_tarihi: "Yayın Tarihi",
  yeni_sube_id: "Yeni Şube",
  yil: "Yıl",
  zimmet_durumu: "Zimmet Durumu",
  acil_durum_telefon: "Acil Durum Telefonu",
  acil_durum_kisi: "Acil Durum Kişisi",
  deger_tipi: "Değer Tipi",
  durum_bildirdi_mi: "Durumu Bildirdi Mi",
  duzeltme_nedeni: "Düzeltme Nedeni",
  etki: "Etki",
  etki_tarihi: "Etki Tarihi",
  belge_surec_id: "Belge Süreci",
  kullanici_id: "Kullanıcı",
  gorsel: "Görsel",
  gun_delta: "Gün Değişimi",
  halef_surum_kodu: "Halef Sürüm Kodu",
  hareket_durumu: "Hareket Durumu",
  iptal_gerekcesi: "İptal Gerekçesi",
  istenen_saat: "İstenen Saat",
  requested_local_time: "İstenen Saat",
  kaldirma_gerekcesi: "Kaldırma Gerekçesi",
  kapsam_id: "Kapsam",
  karar_belgesi: "Karar Belgesi",
  karar_gerekcesi: "Karar Gerekçesi",
  kayit_senaryosu: "Kayıt Senaryosu",
  metin: "Metin",
  parametre_kodu: "Parametre Kodu",
  para_birimi: "Para Birimi",
  politika_paketi: "Politika Paketi",
  politika_surum_kodu: "Politika Sürüm Kodu",
  puantaj_sorgusu: "Puantaj Sorgusu",
  qr_token: "QR Kodu",
  red_gerekcesi: "Red Gerekçesi",
  reseal_gerekcesi: "Yeniden Kapatma Gerekçesi",
  revizyon_gerekcesi: "Revizyon Gerekçesi",
  sgk_isveren_adi: "SGK İşveren Adı",
  sgk_isveren_kodu: "SGK İşveren Kodu",
  sayisal_parametre: "Sayısal Parametre",
  surec_esleme_paketi: "Süreç Eşleme Paketi",
  ters_kayit_aciklamasi: "Ters Kayıt Açıklaması",
  teslim_eden: "Teslim Eden",
  tur: "Tür",
  belge_id: "Belge",
  belge_sha256: "Belge Doğrulama Kodu",
  business_date: "İş Günü Tarihi",
  degerler: "Değerler",
  durum: "Durum",
  event_type: "Hareket Türü",
  gun_kapsami: "Gün Kapsamı",
  kategori: "Kategori",
  kaynak_referansi: "Kaynak Referansı",
  kaynak_turu: "Kaynak Türü",
  neden_kodu: "Neden",
  tatil_adi: "Tatil Adı",
  tatil_kodu: "Tatil Kodu",
  tatil_turu: "Tatil Türü",
  ucret_tutari: "Ücret Tutarı",
  ucret_turu: "Ücret Türü",
  sirket_adi: "Şirket Adı",
  sirket_kodu: "Şirket Kodu",
  sube_kodu: "Şube Kodu",
  sube_adi: "Şube Adı",
  action: "İşlem",
  karar: "Karar",
  karar_degeri: "Karar",
  nihai_karar: "Nihai Karar",
  kimlik: "Kimlik",
  puantaj_donemi: "Puantaj Dönemi",
  saat: "Saat",
  ucret_kaynagi: "Ücret Kaynağı",
  oran: "Oran",
  matrah: "Matrah",
  vergi: "Vergi",
  deadline_at: "Son Tarih",
  yer: "Yer",
  konu: "Konu",
  anomaly_type: "Anomali Türü",
  request_nonce: "İstek Kodu",
  source_event_id: "Kaynak Hareket",
  deployed_sha: "Sürüm Kodu",
  engine_version: "Hesaplama Sürümü",
  giris_cikis_saati: "Giriş Ve Çıkış Saati",
  baslangic_bitis_saati: "Başlangıç Ve Bitiş Saati",
  islem_sirketi: "İşlem Şirketi",
  gecerlilik_baslangic_tarihi: "Geçerlilik Başlangıç Tarihi",
  ucret: "Ücret",
  gercek_mola: "Gerçek Mola Süresi"
};

export function foldTr(text: string): string {
  return text
    .toLocaleLowerCase("tr-TR")
    .replace(/ç/g, "c").replace(/ğ/g, "g").replace(/ı/g, "i").replace(/i̇/g, "i").replace(/ö/g, "o")
    .replace(/ş/g, "s").replace(/ü/g, "u").replace(/[^a-z0-9]+/g, " ").trim();
}

/** Ham mesajlarda geçen alan ifadeleri (katlanmış) → alan anahtarı. */
const PHRASE_ALIASES: Readonly<Record<string, string>> = {
  "tc kimlik no": "tc_kimlik_no", "t c kimlik no": "tc_kimlik_no", "tc": "tc_kimlik_no", sicil: "sicil_no",
  "acil durum telefonu": "acil_durum_telefon", "deger tipi": "deger_tipi", "durumu bildirdi mi": "durum_bildirdi_mi",
  "donem kapanis ozeti icin sube": "sube_id", "maas hesaplama icin sube": "sube_id", "sube": "sube_id",
  "gecerli bir tarih": "tarih", "tarih veya tarih bas tarih bit": "tarih", "belge surec id": "belge_surec_id",
  "personel id": "personel_id", "kullanici id": "kullanici_id", "dahili personel kullanicilari icin personel secimi": "personel_id",
  "gecerlilik baslangic tarihi": "gecerlilik_baslangic_tarihi", "gorsel boyutu veya icerigi": "gorsel", "gun degisimi": "gun_delta",
  "istenen saat": "istenen_saat", "kapsam kimligi": "kapsam_id", "karar belgesi kaniti": "karar_belgesi",
  "onaya gondermek icin karar belgesi kaniti": "karar_belgesi", "metin parametresi": "metin", "metin": "metin",
  "red icin karar notu": "karar_notu", "revizyon gerekcesi": "revizyon_gerekcesi", "sgk isveren adi": "sgk_isveren_adi",
  "sgk isveren kodu": "sgk_isveren_kodu", "sgk isvereni icin sirket secimi": "sirket_id", "sayisal parametre": "sayisal_parametre",
  "sayisal parametre icin": "sayisal_parametre", "ters kayit aciklamasi": "ters_kayit_aciklamasi", "teslim eden bilgisi": "teslim_eden",
  "tur": "tur", "ucret tutari": "ucret_tutari", "ucret turu": "ucret_turu", "iptal gerekcesi": "iptal_gerekcesi",
  "iptal icin neden": "iptal_nedeni", "calistirma iptali icin neden": "iptal_nedeni", "iptal nedeni": "iptal_nedeni",
  "sirket adi": "sirket_adi", "sirket kodu": "sirket_kodu", "diger turu icin aciklama": "aciklama", "harici kaydi": "aciklama",
  "bu hareket durumu icin giris ve cikis saati": "giris_cikis_saati", "yarim gun icin baslangic ve bitis saati": "baslangic_bitis_saati",
  "yarim gun icin interval baslangic bitis": "baslangic_bitis_saati", "saat formati": "saat", "ucret kaynagi": "ucret_kaynagi",
  "puantaj donemi": "puantaj_donemi", "olay karar": "karar", "karar degeri": "karar", "lifecycle gecisi": "durum",
  "decimal string": "tutar", "para birimi": "para_birimi", "politika paketi": "politika_paketi", "politika surum kodu": "politika_surum_kodu",
  "halef surum kodu": "halef_surum_kodu", "suruc esleme paketi": "surec_esleme_paketi", "surec esleme paketi": "surec_esleme_paketi",
  "reseal gerekcesi": "reseal_gerekcesi", "red gerekcesi": "red_gerekcesi", "karar gerekcesi": "karar_gerekcesi",
  "kaldirma gerekcesi": "kaldirma_gerekcesi", "duzeltme nedeni": "duzeltme_nedeni", "etki tarihi": "etki_tarihi", "etki": "etki",
  "hareket durumu": "hareket_durumu", "kayit senaryosu": "kayit_senaryosu", "parametre kodu": "parametre_kodu", "qr token": "qr_token",
  "puantaj sorgusu": "puantaj_sorgusu", "revision inventory icin sube id": "sube_id", "action": "action", "nihai karar": "nihai_karar",
  "kimlik": "kimlik", "formal actor identity icin kullanilabilir ad soyad": "ad_soyad", "kullanilabilir ad soyad": "ad_soyad",
  "savunma belgesi": "belge_turu", "gercek mola dakika": "gercek_mola", "metin parametresi icin deger": "metin", "gorsel boyutu": "gorsel", "ucret turu brut": "ucret_turu",
  "interval baslangic bitis": "baslangic_bitis_saati", "interval": "baslangic_bitis_saati", "sayisal parametre icin gecerli bir deger": "sayisal_parametre", "metin parametresi yalniz metin degeri": "metin", "donem": "donem", "personel": "personel_id", "oran": "oran", "dakika": "dakika", "ay": "ay"
};

const MULTI_ALIASES: Readonly<Record<string, readonly string[]>> = {
  "personel ve dakika": ["personel_id", "dakika"],
  "personel ve departman": ["personel_id", "departman_id"],
  "devir icin personel matrah ve vergi": ["personel_id", "matrah", "vergi"],
  "savunma talebi icin deadline at yer ve konu": ["deadline_at", "yer", "konu"],
  "sube kodu ve sube adi": ["sube_kodu", "sube_adi"]
};

const BY_FOLDED = new Map<string, string>();
for (const [key, label] of Object.entries(FIELD_LABELS)) {
  BY_FOLDED.set(foldTr(label), key);
  BY_FOLDED.set(foldTr(key.replace(/_/g, " ")), key);
  BY_FOLDED.set(foldTr(key.replace(/_ids?$/, "").replace(/_/g, " ")), key);
}
for (const [phrase, key] of Object.entries(PHRASE_ALIASES)) BY_FOLDED.set(phrase, key);

/** Alan anahtarı veya ham metindeki alan adı → Title Case etiket; bilinmiyorsa null. */
export function fieldLabelOf(fieldOrText: string | null | undefined): string | null {
  if (!fieldOrText) return null;
  const direct = FIELD_LABELS[fieldOrText];
  if (direct) return direct;
  const key = BY_FOLDED.get(foldTr(fieldOrText));
  return key ? (FIELD_LABELS[key] ?? null) : null;
}

/** Etiketten alan anahtarı (ekrandaki kontrolü bulmak için). */
export function fieldKeyOfLabel(label: string): string | null {
  for (const [key, value] of Object.entries(FIELD_LABELS)) {
    if (value === label) return key;
  }
  return null;
}

function keysOfPhrase(phrase: string): string[] | null {
  let p = foldTr(phrase).replace(/\b(alani|alanlari)$/, "").trim();
  if (MULTI_ALIASES[p]) return [...MULTI_ALIASES[p]!];
  if (BY_FOLDED.has(p)) return [BY_FOLDED.get(p)!];
  // "X icin Y" → Y
  if (p.startsWith("icin ")) p = ` ${p}`;
  const icin = p.lastIndexOf(" icin ");
  if (icin >= 0) {
    p = p.slice(icin + 6).trim();
    if (BY_FOLDED.has(p)) return [BY_FOLDED.get(p)!];
  }
  return null;
}

const PREDICATE =
  /\s(veya|gecerli|zorunlu|gerekli|bos olamaz|bos birakilamaz|bos olmali|gecersiz|gecerli json|cok uzun|en az|en fazla|olmalidir|olmali|sifir olamaz|sifirdan kucuk olamaz|tam sayi|sifirdan|pozitif|izin verilen uzunlugu|gecerli bir sayi|sayisal|\d|[a-z]+ veya [a-z]+ olmali|formatinda|yalniz|uyusmuyor|gelecekte|secilmelidir|girilmelidir|birlikte|ile baslamali|uc harfli|hh mm|olmadan)/g;

function firstPredicate(text: string): { index: number } | null {
  PREDICATE.lastIndex = 0;
  let m: RegExpExecArray | null;
  while ((m = PREDICATE.exec(text))) {
    if (m.index > 0) return { index: m.index };
  }
  return null;
}

/** Kısıt cümlesini Title Case sonek olarak üretir. */
function constraintSuffix(rest: string, rawRest: string): string {
  const r = rest;
  let m: RegExpExecArray | null;
  if ((m = /^(\d+) (\d+) karakter/.exec(r))) return `${m[1]} İle ${m[2]} Karakter Arasında Olmalıdır.`;
  if ((m = /en az (\d+) karakter/.exec(r))) return `En Az ${m[1]} Karakter Olmalıdır.`;
  if ((m = /en fazla (\d+) karakter/.exec(r))) return `En Fazla ${m[1]} Karakter Olabilir.`;
  if ((m = /(\d+) ile (\d+) arasinda/.exec(r))) return `${m[1]} İle ${m[2]} Arasında Olmalıdır.`;
  if (/zorunlu|gerekli|bos olamaz|bos birakilamaz|secilmelidir|girilmelidir|doldur/.test(r)) return "Boş Bırakılamaz.";
  if (/sifirdan buyuk|pozitif/.test(r)) return "Sıfırdan Büyük Olmalıdır.";
  if (/sifirdan kucuk olamaz/.test(r)) return "Sıfırdan Küçük Olamaz.";
  if (/sifir olamaz/.test(r)) return "Sıfır Olamaz.";
  if (/tam sayi/.test(r)) return "Tam Sayı Olmalıdır.";
  if (/cok uzun|uzunlugu asiyor/.test(r)) return "Çok Uzun. Lütfen Kısaltın.";
  if (/hh mm|saat formati/.test(r)) return "SS:DD Biçiminde Olmalıdır.";
  if (/05xx/.test(r)) return "05XX XXX XX XX Biçiminde Olmalıdır.";
  if (/ veya /.test(r) && /[A-ZÇĞİÖŞÜ_]{2,} veya [A-ZÇĞİÖŞÜ_]{2,}/.test(rawRest)) return "Geçerli Değil. Listeden Seçin.";
  if (/sayisal|gecerli bir sayi|sayi olmali/.test(r)) return "Sayı Olmalıdır.";
  if (/gelecekte/.test(r)) return "İleri Bir Tarih Olmalıdır.";
  if (/bos olmali/.test(r)) return "Bu Seçimde Boş Bırakılmalıdır.";
  if (/ veya /.test(r) && /[A-Z_]{3,} veya [A-Z_]{3,}/.test(rawRest)) return "Geçerli Değil. Listeden Seçin.";
  return "Geçerli Değil. Lütfen Kontrol Edin.";
}

export type ParsedFieldError = { keys: string[]; labels: string[]; text: string };

const WHOLE_MESSAGE: [RegExp, string][] = [
  [/^(tum )?zorunlu alanlari doldurun/, "Zorunlu Alanları Doldurun."],
  [/^en az bir departman secilmelidir/, "En Az Bir Departman Seçin."],
  [/en az bir islem sirketi secilmelidir/, "En Az Bir İşlem Şirketi Seçin."],
  [/en az bir sube sirket veya sgk kapsami zorunlu/, "En Az Bir Şube, Şirket Veya SGK Kapsamı Seçin."],
  [/^alan zorunlu veya cok uzun/, "Zorunlu Alanları Doldurun Ve Uzun Metinleri Kısaltın."],
  [/^(personel )?hesabiniz personel kaydiyla eslestirilmemis/, "Hesabınız Bir Personel Kaydıyla Eşleştirilmemiş. Sistem Yöneticinize Başvurun."],
  [/^haric kaydi aciklama olmadan/, "Hariç Kaydı İçin Açıklama Boş Bırakılamaz."],
  [/^anonim gonderim kapali/, "Anonim Gönderim Kapalı. Adınızla Gönderin."],
  [/^ters kayit tekrar terslenemez/, "Ters Kayıt Tekrar Terslenemez."],
  [/^kimlik dogrulanamadi/, "Kimlik Doğrulanamadı. Yeniden Giriş Yapın."],
  [/^fazla calisma dakikasi sifir/, "Fazla Çalışma Süresi Sıfır Olduğu İçin Kayıt Oluşturulmadı."],
  [/^yalniz jpeg png veya webp/, "Yalnızca JPEG, PNG Veya WEBP Görsel Yükleyin."]
];

/**
 * t869/t871: alan hatasını çözümler. "Gerekce en az 5 karakter olmalıdır." →
 * "Gerekçe En Az 5 Karakter Olmalıdır."; "Gecersiz sube." → "Şube Geçerli Değil. Lütfen Kontrol Edin.".
 * Alan tanınmazsa null.
 */
export function parseFieldError(raw: string | null | undefined, field?: string | null): ParsedFieldError | null {
  if (!raw && !field) return null;
  const cleaned = (raw ?? "").trim().replace(/^[A-Z_]+:\s*/, "");
  const folded = foldTr(cleaned);
  for (const [re, text] of WHOLE_MESSAGE) {
    if (re.test(folded)) return { keys: [], labels: [], text };
  }
  let keys: string[] | null = field && FIELD_LABELS[field] ? [field] : null;
  let rest = folded;
  const rawRest = cleaned;
  let m: RegExpExecArray | null;
  if ((m = /^gecersiz (.+?)$/.exec(folded))) {
    keys = keys ?? keysOfPhrase(m[1]!);
    rest = "gecersiz";
  } else if ((m = /^gecerli bir (.+?) (girin|giriniz|seciniz|secin|zorunludur)\b/.exec(folded))) {
    keys = keys ?? keysOfPhrase(m[1]!);
    rest = "gecersiz";
  } else {
    // Büyük harfli seçenek kodları (TAM_GUN, ALLOW) alan adı değildir; ifade aranırken çıkarılır.
    const sade = ` ${foldTr(cleaned.replace(/\b(?!SGK\b)[A-Z][A-Z_]{2,}\b/g, " "))}`;
    const p = firstPredicate(sade);
    if (p) {
      const phrase = sade.slice(0, p.index).trim();
      keys = keys ?? (phrase ? keysOfPhrase(phrase) : null);
      rest = `${sade.slice(p.index).trim()} ${folded}`;
    }
  }
  if (!keys || keys.length === 0) return null;
  if (/11 hane/.test(folded) && keys[0] === "tc_kimlik_no") {
    return { keys, labels: ["T.C. Kimlik No."], text: "T.C. Kimlik No. 11 Haneli Ve Yalnızca Rakam Olmalıdır." };
  }
  const labels = keys.map((k) => FIELD_LABELS[k]!).filter(Boolean);
  const joined = labels.length <= 1 ? labels.join("") : `${labels.slice(0, -1).join(", ")} Ve ${labels[labels.length - 1]}`;
  return { keys, labels, text: `${joined} ${constraintSuffix(rest, rawRest)}` };
}

/** Geriye uyum: ham metindeki alan adı (yalnız bilinen alanlar). */
export function fieldLabelFromRaw(raw: string | null | undefined): string | null {
  return parseFieldError(raw)?.labels[0] ?? null;
}
