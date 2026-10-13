/**
 * Onaylı karar (t871, OZEL-MESAJLAR-KISA.md 39 satır): genel "İşlem Tamamlanamadı" /
 * "Bilgiler Yüklenemedi" metnine düşmemesi gereken, duruma özel kullanıcı mesajları.
 * Desen, ham mesajın Türkçe küçük harfli hali + hata kodu üzerinde aranır (büyük/küçük harf duyarsız).
 * Backend aynası: api/src/Http/OzelMesajlar.php (scripts/gen-ozel-mesajlar-php.mjs ile üretilir;
 * guard testi eşitliği kontrol eder). Desenlerde tek tırnak ve ~ kullanılmaz.
 */
export type OzelMesajKurali = { satir: number; desen: string; metin: string };

const ENTITY_KAYIT: [string, string][] = [
  ["sgk i[sş]vereni?", "SGK İşvereni"],
  ["[sş]irket", "Şirket"],
  ["[sş]ube", "Şube"],
  ["departman", "Departman"],
  ["kullan[ıi]c[ıi]", "Kullanıcı"]
];

export const OZEL_MESAJ_KURALLARI: readonly OzelMesajKurali[] = [
  { satir: 39, desen: "t\\.?\\s?c\\.? kimlik no\\.? 11 hane", metin: "T.C. Kimlik No. 11 Haneli Ve Yalnızca Rakam Olmalıdır." },
  { satir: 1, desen: "i[sş]ten ayr[ıi]lma s[uü]reci manuel iptal", metin: "İşten Ayrılma Süreci İptal Edilemez. Personeli Geri Almak İçin Yeniden Aktif İşlemini Kullanın." },
  { satir: 2, desen: "personel hesaplar[ıi] y[oö]netim paneli", metin: "Personel Hesabı Buradan Açılamaz. Personel Kartındaki Hesap Oluşturma Adımını Kullanın." },
  { satir: 3, desen: "kullan[ıi]c[ıi] ad[ıi] canonical|canonical kullan[ıi]c[ıi] ad[ıi]", metin: "Kullanıcı Adı Personelin Adı Ve Soyadıyla Uyuşmuyor. Önce Kullanıcı Adını Düzeltin." },
  { satir: 4, desen: "personel kayd[ıi] ba[sş]ka bir kullan[ıi]c[ıi]ya ba[gğ]l[ıi]", metin: "Bu Personel Başka Bir Kullanıcı Hesabına Bağlı. Farklı Bir Personel Seçin." },
  { satir: 5, desen: "rol[uü] canonical modele [cç][oö]z[uü]lemedi", metin: "Hesabınızın Rolü Tanımlı Değil. Sistem Yöneticinizden Rolünüzü Düzenlemesini İsteyin." },
  { satir: 6, desen: "giri[sş] s[ıi]ras[ıi]nda bir hata|first-login credential", metin: "Giriş Yapılamadı. Bilgilerinizi Kontrol Edip Tekrar Deneyin." },
  ...ENTITY_KAYIT.map(([d, ad]) => ({
    satir: 7,
    desen: `^(${d})( kayd[ıi]| tan[ıi]m[ıi])? (kaydedilemedi|olu[sş]turulamad[ıi]|g[uü]ncellenemedi|eklenemedi)`,
    metin: `${ad} Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.`
  })),
  ...ENTITY_KAYIT.map(([d, ad]) => ({
    satir: 8,
    desen: `^(${d})( kayd[ıi]| tan[ıi]m[ıi])? (kal[ıi]c[ıi] olarak )?silinemedi`,
    metin: `${ad} Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin.`
  })),
  { satir: 9, desen: "silinebilirlik kontrol", metin: "Bu Kaydın Silinip Silinemeyeceği Kontrol Edilemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 10, desen: "saklama paneli y[uü]klenemedi", metin: "Saklama Paneli Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 10, desen: "saklama s[uü]resi de[gğ]erlendirilemedi", metin: "Saklama Süresi Hesaplanamadı. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 10, desen: "[uü]cret tipi envanteri y[uü]klenemedi", metin: "Ücret Tipi Listesi Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 10, desen: "y[oö]netim paneli y[uü]klenemedi", metin: "Yönetim Paneli Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 11, desen: "koruma alt[ıi]na al[ıi]namad", metin: "Koruma Altına Alma Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin." },
  { satir: 11, desen: "koruma kald[ıi]r[ıi]lamad", metin: "Koruma Kaldırma Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin." },
  { satir: 11, desen: "saklama s[uü]resi bilgisi al[ıi]namad", metin: "Saklama Süresi Sorgusu Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin." },
  { satir: 11, desen: "imha talebi olu[sş]turulamad", metin: "İmha Talebi Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin." },
  { satir: 11, desen: "imha onay[ıi] i[sş]lenemedi", metin: "İmha Onayı Yapılamadı. Kayıt Değişmedi; Tekrar Deneyin." },
  { satir: 12, desen: "ar[sş]iv\\.(view|download) yetkisi", metin: "Pasif Personelin Arşiv Bilgilerini Görmek İçin Arşiv Yetkisi Gerekir. Sistem Yöneticinize Başvurun." },
  { satir: 13, desen: "demo_test_verisi", metin: "Demo Test Verisi Seçeneğini Yalnızca Genel Yönetici Kullanabilir." },
  { satir: 14, desen: "bordro [oö]n izleme yetkisi gerekir", metin: "Bu Ekran İçin Bordro Ön İzleme Yetkisi Gerekir. Sistem Yöneticinize Başvurun." },
  { satir: 15, desen: "haz[ıi]rlayan kullan[ıi]c[ıi] politikay[ıi] onaylayamaz", metin: "Politikayı Hazırlayan Kişi Onaylayamaz. Onayı Başka Bir Yetkili Vermelidir." },
  { satir: 16, desen: "kan[ıi]t ge[cç]erli de[gğ]il; onay kapal", metin: "Kanıt Belgesi Geçerli Değil, Bu Yüzden Onay Verilemiyor. Belgeyi Yenileyip Tekrar Deneyin." },
  { satir: 17, desen: "belge_id ve belge_sha256 birlikte", metin: "Belge Ve Belge Doğrulama Bilgisi Birlikte Girilmelidir." },
  { satir: 18, desen: "politika tasla[gğ][ıi] olu[sş]turulamad", metin: "Politika Taslağı Oluşturulamadı. Tekrar Deneyin." },
  { satir: 18, desen: "politika onaya g[oö]nderilemedi", metin: "Politika Onaya Gönderilemedi. Tekrar Deneyin." },
  { satir: 18, desen: "politika onaylanamad", metin: "Politika Onaylanamadı. Tekrar Deneyin." },
  { satir: 19, desen: "maa[sş] aday[ıi] [uü]retilemedi|bordro kesinle[sş]tirilemedi|^devir kaydedilemedi", metin: "Maaş İşlemi Tamamlanamadı, Hiçbir Değişiklik Kaydedilmedi. Ön Kontrolü Yeniden Çalıştırıp Tekrar Deneyin." },
  { satir: 20, desen: "aktif snapshot sonras[ıi] kaynaklar de[gğ]i[sş]ti", metin: "Hesap Kaydından Sonra Puantaj Veya Ücret Bilgileri Değişti. Önce Mevcut Hesabı İptal Edip Yeniden Hesaplayın." },
  { satir: 21, desen: "[oö]nce [oö]n kontrol [cç]al[ıi][sş]t[ıi]r[ıi]lmal|[oö]nce ge[cç]erli [oö]n kontrol sonucu|i[cç]e aktarma [oö]n kontrol[uü] uygun de[gğ]il", metin: "Önce Ön Kontrolü Çalıştırın Ve Hataları Giderin, Sonra Devam Edin." },
  { satir: 22, desen: "(aday listesi|aday kalemleri|snapshot detay[ıi]) al[ıi]namad|bordro haz[ıi]rl[ıi]k verisi y[uü]klenemedi|devir [sş]ablonu indirilemedi", metin: "Maaş Hesaplama Bilgileri Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 23, desen: "^(snapshot |[cç]al[ıi][sş]t[ıi]rma |[uü]cret kayd[ıi] )?iptal (edilemedi|ba[sş]ar[ıi]s[ıi]z)", metin: "İptal Yapılamadı, Kayıt Olduğu Gibi Duruyor. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 24, desen: "rapor verisi al[ıi]namad|ayl[ıi]k [oö]zet y[uü]klenemedi|serbest zaman takip y[uü]klenemedi|puantaj raporu d[ıi][sş]a aktar[ıi]lamad", metin: "Rapor Hazırlanamadı. Filtreleri Kontrol Edip Tekrar Deneyin." },
  { satir: 25, desen: "sgk csv i[cç]in ay ve [sş]ube", metin: "SGK Dosyası İçin Önce Ay Ve Şube Seçin." },
  { satir: 26, desen: "(b[oö]l[uü]m onay[ıi]|[uü]st onay|genel y[oö]netici bildirim onay[ıi]) tamamlanamad", metin: "Onay Kaydedilemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 28, desen: "manuel giri[sş] kullan[ıi]labilir", metin: "Seçenekler Yüklenemedi. Bilgiyi Elle Girerek Devam Edebilirsiniz." },
  { satir: 27, desen: "[sş]u an g[uü]ncellenemiyor", metin: "Liste Şu An Güncellenemiyor; Son Kaydedilen Bilgiler Gösteriliyor. Biraz Sonra Sayfayı Yenileyin." },
  { satir: 29, desen: "ge[cç]erli bir (bildirim [oö]zeti|g[uü]nl[uü]k kay[ıi]t|personel|s[uü]re[cç]) id verilmedi", metin: "Açılmak İstenen Kayıt Bulunamadı. Listeden Kaydı Seçerek Tekrar Açın." },
  { satir: 30, desen: "kayd[ıi] g[uü]ncellemek i[cç]in [oö]nce personel ve tarih", metin: "Kaydı Güncellemek İçin Önce Personel Ve Tarih Seçin." },
  { satir: 31, desen: "m[uü]h[uü]rleme i[sş]lemi ba[sş]ar[ıi]s[ıi]z|kaynak m[uü]h[uü]r de[gğ]i[sş]mi[sş]", metin: "Dönem Mühürlenemedi; Dönem Bilgileri Bu Arada Değişmiş Olabilir. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 32, desen: "aday[ıi]n kaynak verisi do[gğ]rulanamad|aday daha [oö]nce farkl[ıi] bir manuel kararla", metin: "Bu Aday Bu Arada Değişti Veya Başka Bir Kararla Uygulandı. Liste Yenilendi; Kontrol Edip Tekrar Karar Verin." },
  { satir: 33, desen: "a[cç][ıi]k giri[sş] kayd[ıi] varken yeni giri[sş]", metin: "Açık Bir Giriş Kaydınız Var. Önce Çıkış Okutun Veya Düzeltme Talebi Açın." },
  { satir: 33, desen: "a[cç][ıi]k giri[sş] olmadan [cç][ıi]k[ıi][sş]", metin: "Giriş Kaydınız Yok. Önce Giriş Okutun." },
  { satir: 34, desen: "qr token surumu|qr suresi doldu|qr okuma hatas", metin: "QR Kod Okunamadı Ya Da Süresi Doldu. Ekrandaki Güncel QR Kodu Tekrar Okutun." },
  { satir: 35, desen: "kamera i[cç]in g[uü]venli ba[gğ]lant|kamera eri[sş]imini desteklemiyor", metin: "Kamera Açılamadı. Uygulamayı Güvenli Bağlantıyla (HTTPS) Açın Veya Kamerası Olan Bir Cihaz Kullanın." },
  { satir: 36, desen: "bekleyen d[uü]zeltme talebi var", metin: "Düzeltme Talebi Açılamadı: Bu Hareket İçin Bekleyen Talep Var." },
  { satir: 36, desen: "a[cç][ıi]k giri[sş]/[cç][ıi]k[ıi][sş] anomalisi yok", metin: "Düzeltme Talebi Açılamadı: Düzeltilecek Açık Kayıt Yok." },
  { satir: 36, desen: "uygun ba[gğ][ıi]ms[ıi]z y[oö]netici bulunamad", metin: "Düzeltme Talebi Açılamadı: Onaylayacak Yönetici Bulunamadı. Amirinizle Görüşün." },
  { satir: 36, desen: "d[uü]zeltme talebi s[uü]resi doldu", metin: "Düzeltme Talebi Açılamadı: Talep Süresi Doldu. Amirinizle Görüşün." },
  { satir: 37, desen: "(tarih penceresi|tarih aral[ıi][gğ][ıi]) en fazla 31 g[uü]n", metin: "Tarih Aralığı Uygun Değil: Aralık En Fazla 31 Gün Olabilir." },
  { satir: 37, desen: "(tarih penceresi|tarih aral[ıi][gğ][ıi]) en fazla 366 g[uü]n", metin: "Tarih Aralığı Uygun Değil: Aralık En Fazla 366 Gün Olabilir." },
  { satir: 37, desen: "(biti[sş]|kapan[ıi][sş]) tarihi .{0,30}[oö]nce olamaz|gecerlilik_bitis ba[sş]lang[ıi][cç]tan [oö]nce", metin: "Tarih Aralığı Uygun Değil: Bitiş Tarihi Başlangıçtan Önce Olamaz." },
  { satir: 40, desen: "disiplin aday vakas[ıi] olu[sş]turulamad", metin: "Disiplin Vakası Oluşturulamadı. Bilgileri Kontrol Edip Tekrar Deneyin." },
  { satir: 40, desen: "savunma belgesi bu a[sş]amada eklenemez", metin: "Savunma Belgesi Bu Aşamada Eklenemez. Önce Savunma Talebini Tamamlayın." },
  { satir: 40, desen: "vaka bu a[sş]amada i[sş]lemsiz kapat[ıi]lamaz", metin: "Vaka Bu Aşamada İşlem Yapılmadan Kapatılamaz. Önce Karar Verin." },
  { satir: 40, desen: "belge s[uü]reci personel ile uyu[sş]muyor", metin: "Seçilen Belge Bu Personele Ait Değil. Doğru Belgeyi Seçin." },
  { satir: 41, desen: "^talep olu[sş]turulamad", metin: "Talep Oluşturulamadı. Bilgileri Kontrol Edip Tekrar Deneyin." },
  { satir: 42, desen: "olay karar[ıi] kaydedilemedi", metin: "Olay Kararı Kaydedilemedi. Sayfayı Yenileyip Tekrar Deneyin." },
  { satir: 38, desen: "okundu i[sş]aretlenemedi", metin: "Okundu Olarak İşaretlenemedi. Biraz Sonra Tekrar Deneyin." }
];

const DERLENMIS = OZEL_MESAJ_KURALLARI.map((k) => ({ ...k, re: new RegExp(k.desen, "i") }));

export const OZEL_METINLER: ReadonlySet<string> = new Set(OZEL_MESAJ_KURALLARI.map((k) => k.metin));

const YUKLENEMEDI = /^([A-Za-zÇĞİÖŞÜçğıöşü ]{2,40}?) y[uü]klenemedi\.?( [Tt]ekrar deneyin\.?)?$/;

/**
 * Satır 10 kalıbı (ön yüz): "[Bölüm] yüklenemedi." → "[Bölüm] Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin."
 * Yalnız Türkçe karakterleri doğru yazılmış ön yüz metinleri için (sunucu metinleri "okunamadi" kullanır).
 */
function yuklenemediMetni(raw: string): string | null {
  const m = YUKLENEMEDI.exec(raw.trim());
  if (!m?.[1] || !/[çğıöşüÇĞİÖŞÜ]|^[a-zA-Z ]+$/.test(m[1])) return null;
  const bolum = m[1]
    .split(" ")
    .map((w) => (w ? (w[0] === "i" ? "İ" : w[0] === "ı" ? "I" : w[0]!.toLocaleUpperCase("tr-TR")) + w.slice(1) : w))
    .join(" ");
  return `${bolum} Yüklenemedi. Sayfayı Yenileyip Tekrar Deneyin.`;
}

export function isYuklenemediMetni(text: string): boolean {
  return /^[^.]+ Yüklenemedi\. Sayfayı Yenileyip Tekrar Deneyin\.$/.test(text.trim());
}

/** Ham mesaj özel bir duruma karşılık geliyorsa Title Case metnini döner. */
export function ozelMesaj(raw: string | null | undefined, code?: string | null): string | null {
  if (!raw) return null;
  const lower = raw.trim().toLocaleLowerCase("tr-TR");
  const adaylar = [lower, raw.trim(), `${code ?? ""} ${lower}`.trim()];
  for (const k of DERLENMIS) {
    if (adaylar.some((t) => k.re.test(t))) return k.metin;
  }
  return yuklenemediMetni(raw);
}
