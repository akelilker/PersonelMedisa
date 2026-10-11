# Tam veritabanı yedeği

Bu yedek, migration öncesi aynı sunucuda doğrulama içindir. Sunucu dışı felaket kurtarma kopyası değildir. Döküm web kökünün dışına yazılır, düz FTP ile taşınmaz ve disk sunucuyla birlikte gider. Sunucu kaybolursa bu dosya da kaybolur.

## Yetki

Yedeği alan hesap, yapılandırmadaki veritabanı hesabıdır. İkinci bir yönetici hesabı yoktur. Geçici veritabanı açmak için `SHOW GRANTS` çıktısında `*.*` üzerinde `CREATE` ve `DROP` (veya `ALL PRIVILEGES`) görünmelidir. Yalnız canlı veritabanı üzerindeki `ALL PRIVILEGES` veya `USAGE` bunu karşılamaz; bu durumda sonuç `RESTORE_PRIVILEGE_ABSENT` olur ve canlı veritabanına geri yükleme yapılmaz. Yetki metni okunamazsa yedek, doğrulanmış yetki sayılmaz.

## Dosya biçimi

Dosya mysql istemci betiği değildir. Baştaki `--` satırları açıklamadır. Her kayıt `S`, ardından SQL'in bayt uzunluğu, satır sonu, SQL metni ve satır sonundan oluşur. Tanımlardaki `DEFINER` geri yüklemede yazılmaz; nesne, geri yükleyen hesabın nesnesi olur.

## Geri yükleme

Geri yükleme hedefi, canlı adın ` _r` ile başlayan bir kardeş veritabanıdır. Canlı ad reddedilir. İşçi bunu kendisi yapar, karşılaştırır ve kardeşi siler. Elle tekrar için aynı dosya `FullDatabaseBackupService::restoreInto` ile, canlı olmayan ve adı `{canlı}_r` ile başlayan bir veritabanına oynatılır. Dosya mysql istemcisine verilmez.

Migration 100, işçi APPLY aşamasına gelmeden önce bu dosyanın durduğunu, SHA-256 özetinin kanıtla aynı olduğunu, dağıtım sürümünün ve migration seviyesinin uyduğunu, kaydın 12 saatten yeni olduğunu ve üç yetki tablosunun o anda da bulunmadığını yeniden kontrol eder. Biri tutmazsa APPLY başlamaz.
