# Tam veritabanı yedeği

Bu yedek, migration öncesi aynı sunucuda doğrulama içindir. Sunucu dışı felaket kurtarma kopyası değildir. Döküm web kökünün dışına yazılır, düz FTP ile taşınmaz ve disk sunucuyla birlikte gider. Sunucu kaybolursa bu dosya da kaybolur.

## Yetki

Yedeği alan hesap, yapılandırmadaki veritabanı hesabıdır. İkinci bir yönetici hesabı yoktur. İki geri yükleme yolu vardır. Hesabın `SHOW GRANTS` çıktısında `*.*` üzerinde `CREATE` ve `DROP` (veya `ALL PRIVILEGES`) varsa geçici kardeş veritabanı açılır ve işlem bitince silinir. Paylaşımlı hostingte bu genel yetki yoktur. O zaman sunucu yapılandırmasındaki `db_restore_name` kullanılır: bu ad önceden açılmış, boş ve yalnız bu işe ayrılmış veritabanıdır. Ad istekten veya iş akışı parametresinden okunmaz. Canlı veritabanı hedef olamaz. Hedefte başka nesne varsa silinmez ve işlem durur. Doğrulama bitince nesneler temizlenir, veritabanının kendisi kalır. Yalnız canlı veritabanındaki yetki ve boş `db_restore_name` sonucu `RESTORE_PRIVILEGE_ABSENT` olur. Hedef veritabanında gerekli yetki yoksa sonuç `RESTORE_TARGET_PRIVILEGE_ABSENT` olur. Yetki metni okunamazsa yedek, doğrulanmış yetki sayılmaz.

## Dosya biçimi

Dosya mysql istemci betiği değildir. Baştaki `--` satırları açıklamadır. Her kayıt `S`, ardından SQL'in bayt uzunluğu, satır sonu, SQL metni ve satır sonundan oluşur. Tanımlardaki `DEFINER` geri yüklemede yazılmaz; nesne, geri yükleyen hesabın nesnesi olur.

## Geri yükleme

Geçici hedef, canlı adın `_r` ile başlayan bir kardeş veritabanıdır ve işlem bitince silinir. Hazır hedef, `db_restore_name` ile birebir aynı olan veritabanıdır; kendisi silinmez. Canlı ad her iki yolda da reddedilir. İşçi karşılaştırmayı kendisi yapar. Elle tekrar için aynı dosya `FullDatabaseBackupService::restoreInto` ile, canlı olmayan ve ya `{canlı}_r` ile başlayan ya da yapılandırılmış hazır hedef olan bir veritabanına oynatılır. Dosya mysql istemcisine verilmez.

Migration 100, işçi APPLY aşamasına gelmeden önce bu dosyanın durduğunu, SHA-256 özetinin kanıtla aynı olduğunu, dağıtım sürümünün ve migration seviyesinin uyduğunu, kaydın 12 saatten yeni olduğunu ve üç yetki tablosunun o anda da bulunmadığını yeniden kontrol eder. Biri tutmazsa APPLY başlamaz.

## Yedekten Geri Yükle — plan (t900 C, UYGULANMADI)

Durum: **Kod yazılmadı, canlıda geri yükleme çalıştırılmadı.** Ayarlar > Veri Yedekleme'deki "Yedekten Geri Yükle" düğmesi kapalı kalır.

Neden durduruldu: Bu sahibin temel kuralı "canlı veritabanı asla hedef değildir"dir (`restoreInto` canlı adı reddeder). Ekrandan geri yükleme bu kuralı kaldırmayı gerektirir. MariaDB'de DDL işlem içinde geri alınamaz; yarıda kalan bir `DROP TABLE` + `INSERT` oynatması canlıyı kısmen boş bırakır. cPanel'de PHP istek süresi ve paylaşımlı barındırma yetkileri (ikinci veritabanı, çapraz veritabanı `RENAME TABLE`) canlıda prova edilemeden doğrulanamaz.

Güvenli tasarım (onay sonrası ayrı PR):

1. Yetki: yalnız `GENEL_YONETICI`, oturum + yeniden şifre doğrulaması.
2. Onay: yedek seçimi (tarih, migration seviyesi, satır sayısı gösterilir) ve "GERİ YÜKLE" yazılarak onay. Seçilen yedeğin migration seviyesi canlı koddan farklıysa reddedilir.
3. Otomatik ön yedek: geri yüklemeden hemen önce `createAppBackup` ile tam yedek; SHA-256 okunarak doğrulanmazsa işlem başlamaz.
4. Hazırlık: seçilen dosya `db_restore_name` hazır hedefine `restoreInto` ile oynatılır, `inventory`/`compare` ile tablo, satır, indeks, FK sayıları doğrulanır. Canlıya dokunulmaz.
5. Geçiş: bakım kipi (yazma uçları 503), ardından tek `RENAME TABLE canli.t TO arsiv.t, hazir.t TO canli.t, …` ifadesiyle atomik takas. Eski tablolar arşiv veritabanında kalır; geri dönüş aynı takasın tersidir.
6. Arka planda çalışır (cron/worker), istek yalnız iş açar; ilerleme ve sonuç `veri-yedekleme-audit.log` + ekranda durum olarak görünür.
7. Prova: önce ayrı bir test sunucusunda veya geçici veritabanında uçtan uca; canlıda ilk çalıştırma ancak İlker'in açık onayıyla.

Ön koşullar (İlker / barındırma): cPanel hesabında `db_restore_name` ve arşiv veritabanı için yetki, çapraz veritabanı `RENAME` izni, bakım kipi kararı.
