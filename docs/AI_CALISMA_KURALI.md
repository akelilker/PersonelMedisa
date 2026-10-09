PERSONELMEDISA — BİTİRME ODAKLI AI ÇALIŞMA KURALI v3

AMAÇ: Programı bitirmek ve stabil tutmak. Kod tabanını “mükemmelleştirmek”, yeniden tasarlamak veya gereksiz mühendislik yapmak amaç değildir.

1) Kullanıcının açık hedefini değiştirme. Yeni ürün, ekran, mimari, refactor, cleanup, abstraction veya “daha iyi olur” işi uydurma.

2) ÖNCELİK = çalışan ürünü korumak ve bitirmek. Çalışan yapıyı sırf sadeleştirmek, modernleştirmek, birleştirmek veya standardize etmek için değiştirme.

3) Yeni framework/mimari/geçiş yok. React/TypeScript mevcut yapıda kalır; Vanilla’ya, başka frameworke veya büyük yeniden yazıma başlanmaz. Mevcut mimari yalnız gerçek blocker varsa en küçük ölçüde değiştirilir.

4) Refactor/cleanup ancak mevcut hedefi çözmek için teknik olarak zorunluysa yapılır. “Ölü kod temizliği”, “genelleştirme”, “ortaklaştırma”, “isim standardizasyonu”, “component sadeleştirme” kendi başına görev değildir.

5) Bir aktif hedef = bir ekran / bir davranış / bir net sorun. Aynı görevde başka ekranların görünümünü veya davranışını değiştirme.

6) Önce root cause ve gerçek owner bulunur. Doğru owner düzeltilebiliyorsa global CSS override, dosya sonu patch, paralel helper, ikinci component veya geçici workaround eklenmez.

7) GÖRSEL KARARLAR DONMUŞ KURALDIR. Kullanıcının onayladığı son ekran görüntüsü, açık yazılı kararı ve UI sözleşmesi mevcut tasarımın kaynağıdır. Yeni agent bunları “iyileştiremez”. Son açık kullanıcı kararı daha eski kararı geçersiz kılar.

8) Bir görsel işe başlamadan önce yalnız ilgili ekran için şu üç şey karşılaştırılır:
   - current main’deki gerçek görünüm/kod,
   - kullanıcı tarafından onaylanmış son görünüm/karar,
   - varsa UI sözleşmesi.
   Sapma kanıtlanmadan görsel değişiklik yapılmaz.

9) Kullanıcı “eskiye dönmüş / bozulmuş” diyorsa önce READ-ONLY DRIFT AUDIT yapılır. Benzer geri dönüşler aynı ekranda taranır ve liste çıkarılır. Kanıtlanmayan şey “bozulmuş” diye yazılmaz. Düzeltmeler ekran ekran yapılır.

10) Onaylanmış görsel öğeler FROZEN kabul edilir. Hedefle ilgisi olmayan boyut, konum, kolon sayısı, spacing, renk, border, radius, ikon, başlık, footer, safe-area, hover veya responsive davranışına dokunulmaz.

11) Shared/global CSS veya ortak component değişikliği son çaredir. Zorunluysa değişiklikten önce etkilenecek ekranlar belirlenir. Hedef dışı ekran etkisi varsa shared değişiklik yapılmaz; doğru lokal owner bulunur.

12) GÖRSEL TESTİN ASIL KANITI GERÇEK RENDER’DIR. Pixel/yerleşim işi source-string testi geçti diye CLOSED sayılmaz. İlgili gerçek ekran en az kullanıcının kullandığı mobil genişlikte kontrol edilir; desktop etkileniyorsa desktop da kontrol edilir.

13) Test yalnız gerçek riski korumak için yazılır. Her görsel değişikliğe test eklemek zorunlu değildir. Test yazılacaksa kilitlenmiş davranışı korur; implementation detail veya source-string varlığını değil mümkün olduğunca gerçek sonucu doğrular.

14) Bir test mevcut yanlış görünümü doğruluyorsa “test geçiyor” diye yanlış görünüm korunmaz. Kullanıcı kararı / UI sözleşmesi doğru kaynaktır. Test doğru karara göre düzeltilir.

15) Testleri sırf yeni diff yeşil olsun diye güncelleme. Önce ürün davranışının gerçekten değişmesi istenmiş mi kontrol et. Kullanıcı karar vermediyse eski doğru davranış korunur.

16) VALIDATION = minimum necessary. Görsel değişiklikte önce local gerçek render/viewport kontrolü; davranış değişikliğinde ilgili focused test. Full suite rutin iş değildir.

17) GitHub Actions ücretli ve yavaş kaynak kabul edilir. LOCAL-FIRST. Normal akış: branch → local focused kontrol → PR → Fast CI yalnız 1 kez. Merge sonrası ikinci Fast/Full CI yok; main push otomatik deploy eder.

18) Aynı diff/SHA daha önce PASS ise testi tekrar etme. Timeout artırmak, kör rerun veya aynı job’ı defalarca çalıştırmak root-cause çözümü değildir.

19) CLOSED / PASS yalnız ilgili gerçek davranış doğrulandıysa yazılır. Görsel işte “build geçti”, “typecheck geçti”, “test geçti” tek başına kapanış kanıtı değildir.

20) Merge yalnız kullanıcı açıkça “merge et” dediğinde. “Tamam”, “olur”, “güzel”, “gidebilir” merge izni değildir. Merge onayı bilinen otomatik deploy zincirini kapsar; manual deploy/redeploy, migration veya production mutation ayrıca açık onay ister.

21) Production veri uydurma. Direct SQL/phpMyAdmin yok. Production mutation öncesi mevcut değer okunur; exact değişiklik bilinmeden veri yazılmaz.

22) Force push, rebase, hard reset, git clean, gizli stash, kullanıcı değişikliğini silme veya unrelated commit yok.

23) Aktif agent işi bitmeden paralel agent/görev açma. Önce mevcut çıktıyı al ve değerlendir. Bu kural farklı araçları da kapsar (Cursor, ChatGPT/Codex, Cline): aynı ekran/aynı dosya üzerinde aynı anda yalnız TEK araç çalışır.

24) Kullanıcıya terminal komutu ancak gerçekten kullanıcı erişimi gerekiyorsa ver. Agent repo/terminal/GitHub üzerinden yapabiliyorsa kullanıcıyı operatör yapma.

25) Agent promptu kısa ve tek hedefli olur: root cause → exact owner → minimum değişiklik → görsel/odaklı doğrulama → kısa rapor. Uzun geçmiş, gereksiz audit, yeni backlog veya “garanti olsun” adımları eklenmez.

26) Kapsam dışı değişiklik görürsen dokunma; raporla. Hedefi doğru bitirmek için zorunlu bağımlı değişiklik varsa kapsam içidir ama açıkça belirtilir.

27) GÖRSEL KAPANIŞ RAPORU şu sorulara cevap vermeli:
   - Hangi ekran düzeltildi?
   - Önceden onaylanmış hangi karar geri getirildi/korundu?
   - Hedef dışı hangi alanlara dokunulmadı?
   - Gerçek mobil/desktop render kontrolü yapıldı mı?
   - Commit / PR / merge / deploy durumu ne?

28) Teknik dil kullanıcıya yüklenmez. Kullanıcıya “hangi dosya/selector/hook” anlatmak yerine önce ne bozuktu, ne düzeldi, ne kaldı açık Türkçe ile söylenir. Teknik ayrıntı yalnız istenirse verilir.

29) Programı bitirmeye hizmet etmeyen yeni backlog açma. “İleride güzel olur”, “refactor edelim”, “test altyapısını geliştirelim”, “tasarımı standardize edelim” türü işler kullanıcı açıkça istemedikçe yoktur.

30) Bu dosya yalnız ÇALIŞMA ŞEKLİ içindir. Current SHA, açık PR, migration numarası, canlı deploy durumu veya geçici checkpoint buraya yazılmaz; bunlar ayrı kısa devir notunda tutulur.

31) KARAR KAYDI: Kullanıcının onayladığı her görsel karar repoda docs/guncel/onayli-kararlar.md dosyasında tek satır olarak tutulur (ekran | karar | değer | tarih). Agent görsel bir işe başlamadan önce bu dosyayı okur. Kullanıcı yeni karar verdiğinde ilgili satır güncellenir; son açık karar eskisini geçersiz kılar. Bu dosyada geçici durum (SHA, açık PR, deploy) tutulmaz, yalnız kalıcı karar tutulur.

32) YAN ETKİ KONTROLÜ: Bir düzeltmeden sonra aynı ekrandaki daha önce onaylanmış öğeler (renk, boşluk, başlık, ikon, yükseklik) karar kaydına göre tekrar kontrol edilir. Düzeltme onaylı bir öğeyi değiştirdiyse iş kapanmaz.

33) TEK YÖNETİCİ: Repo, branch, PR, merge sonrası senkron ve agent dağıtımı tek yöneticidedir (personel prog). Başka bir araç iş yapacaksa yönetici önceden bilgilendirilir; yönetici o ekranda çalışan başka iş olmadığını doğrular.

34) HER İŞ GÜNCEL MAIN'DEN BAŞLAR: Yeni iş her zaman o anki origin/main'den açılan yeni branch'te yapılır. Eski/merge edilmiş branch'e veya yerelde geride kalmış kopyaya devam edilmez. İşe başlamadan önce "branch tabanı = güncel origin/main" kontrol edilir; değilse iş başlamaz. Bu kontrol her yeni görevin başında bir kez yapılır: GitHub'daki refs/heads/main SHA'sı doğrudan uzaktan (git ls-remote origin refs/heads/main) okunur, yerel origin/main bilgisinin güncel olduğu varsayılmaz; yerel main SHA'sı, aktif branch adı ve kaydedilmemiş kullanıcı değişiklikleri belirlenir. Fark varsa yalnız bildirilir; pull, checkout, reset veya merge otomatik yapılmaz, aktif çalışma ve kullanıcı değişiklikleri korunur. SHA hiçbir dosyada tutulmaz; kontrol görev içinde yalnız somut sürüm uyuşmazlığı şüphesinde tekrarlanır.

35) MERGE SONRASI SENKRON: Her merge'den sonra yereldeki repo main'e geçirilir ve yalnız fast-forward ile origin/main'e getirilir; merge edilmiş branch yerelde açık bırakılmaz. Local main SHA = origin/main SHA doğrulanır.

36) AGENT RAPORU KANIT DEĞİLDİR: Bir agent'ın "PASS" demesi tek başına kapanış değildir. Yönetici ekran görüntülerini kendi gözüyle kontrol eder; yerleşim işinde ölçüm (ör. header ile geri satırı arası px) raporda verilir. Mock veriyle yapılan kontrol raporda "mock" diye, canlı veriyle doğrulanamayan kısım "doğrulanmadı" diye açıkça yazılır.

37) SATIR SONU FARKI DEĞİŞİKLİK DEĞİLDİR: Yalnız CRLF/LF farkı olan dosya commit edilmez. Böyle bir fark görülürse önce gerçek içerik farkı olmadığı kanıtlanır, eski hali yedeklenir, sonra dosya repodaki haline döndürülür. İçerik farkı varsa hiçbir şey geri alınmaz, kullanıcıya sorulur.

38) PR REVİZYONLARI CI HARCAMAZ: Aynı PR'da birden fazla görsel tur gerekecekse turlar local render ile kapatılır; push yalnız kullanıcı görüntüyü onayladıktan sonra veya tur başına tek sefer yapılır. Her push Fast CI çalıştırdığı için "küçük düzeltme push'u" yoktur.

39) KAPANMIŞ İŞ YENİDEN AÇILMAZ: CLOSED / DONE kayıtlar yeni somut çelişki kanıtı olmadan yeniden açılmaz; geniş kapsamlı audit kullanıcı açıkça istemedikçe başlatılmaz.

KISA MOTTO:
ÇALIŞANI KORU. KARARI DEĞİŞTİRME. TEK EKRANI DÜZELT. GERÇEK GÖRÜNÜMÜ KONTROL ET. GÜNCEL MAIN'DEN BAŞLA. PROGRAMI BİTİR.
