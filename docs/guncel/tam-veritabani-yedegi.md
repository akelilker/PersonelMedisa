# Tam veritabanı yedeği

Bu yedek, migration öncesi aynı sunucuda doğrulama içindir. Sunucu dışı felaket kurtarma kopyası değildir. Döküm web kökünün dışına yazılır, düz FTP ile taşınmaz ve disk sunucuyla birlikte gider. Sunucu kaybolursa bu dosya da kaybolur.

## Yetki

Yedeği alan hesap, yapılandırmadaki veritabanı hesabıdır. İkinci bir yönetici hesabı yoktur. İki geri yükleme yolu vardır. Hesabın `SHOW GRANTS` çıktısında `*.*` üzerinde `CREATE` ve `DROP` (veya `ALL PRIVILEGES`) varsa geçici kardeş veritabanı açılır ve işlem bitince silinir. Paylaşımlı hostingte bu genel yetki yoktur. O zaman sunucu yapılandırmasındaki `db_restore_name` kullanılır: bu ad önceden açılmış, boş ve yalnız bu işe ayrılmış veritabanıdır. Ad istekten veya iş akışı parametresinden okunmaz. Canlı veritabanı hedef olamaz. Hedefte başka nesne varsa silinmez ve işlem durur. Doğrulama bitince nesneler temizlenir, veritabanının kendisi kalır. Yalnız canlı veritabanındaki yetki ve boş `db_restore_name` sonucu `RESTORE_PRIVILEGE_ABSENT` olur. Hedef veritabanında gerekli yetki yoksa sonuç `RESTORE_TARGET_PRIVILEGE_ABSENT` olur. Yetki metni okunamazsa yedek, doğrulanmış yetki sayılmaz.

## Dosya biçimi

Dosya mysql istemci betiği değildir. Baştaki `--` satırları açıklamadır. Her kayıt `S`, ardından SQL'in bayt uzunluğu, satır sonu, SQL metni ve satır sonundan oluşur. Tanımlardaki `DEFINER` geri yüklemede yazılmaz; nesne, geri yükleyen hesabın nesnesi olur.

## Geri yükleme

Geçici hedef, canlı adın `_r` ile başlayan bir kardeş veritabanıdır ve işlem bitince silinir. Hazır hedef, `db_restore_name` ile birebir aynı olan veritabanıdır; kendisi silinmez. Canlı ad her iki yolda da reddedilir. İşçi karşılaştırmayı kendisi yapar. Elle tekrar için aynı dosya `FullDatabaseBackupService::restoreInto` ile, canlı olmayan ve ya `{canlı}_r` ile başlayan ya da yapılandırılmış hazır hedef olan bir veritabanına oynatılır. Dosya mysql istemcisine verilmez.

Migration 100, işçi APPLY aşamasına gelmeden önce bu dosyanın durduğunu, SHA-256 özetinin kanıtla aynı olduğunu, dağıtım sürümünün ve migration seviyesinin uyduğunu, kaydın 12 saatten yeni olduğunu ve üç yetki tablosunun o anda da bulunmadığını yeniden kontrol eder. Biri tutmazsa APPLY başlamaz.
