<?php

declare(strict_types=1);

/**
 * Dinamik yetki P3 — yazma kuralları, gerçek tek kullanımlık MariaDB. Canlıya dokunmaz.
 * php tests/php/YetkiYazmaMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\EffectivePermissionResolver as R;
use Medisa\Api\Database\UserYetkiIstisnaSchema as S;
use Medisa\Api\Services\Auth\KullaniciYetkiYazmaService as Y;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;

function ywAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function ywPdo(string $dsn): PDO
{
    return new PDO($dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: 'root', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** Hata kodunu döndürür; başarıda 'OK:' + sonuç json. */
function ywTry(callable $fn): string
{
    try {
        $r = $fn();

        return 'OK:' . json_encode($r);
    } catch (OrganizasyonException $e) {
        return $e->errorCode;
    }
}

$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($rootDsn === '' || stripos($rootDsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $rootDsn, $m) && !in_array(strtolower($m[1]), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}
$baseDsn = preg_replace('/;?dbname=[^;]*/i', '', $rootDsn) ?: $rootDsn;
$root = ywPdo($baseDsn);
$db = 'medisa_yw_' . bin2hex(random_bytes(4));
$legacyDb = 'medisa_yw_legacy_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('CREATE DATABASE `' . $legacyDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = null;
$pdo2 = null;
$legacy = null;

try {
    $chain = array_values(array_filter(scandir(__DIR__ . '/../../api/migrations') ?: [], static fn ($n) => (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $n)));
    sort($chain, SORT_STRING);
    $pdo = ywPdo($baseDsn . ';dbname=' . $db);
    $legacy = ywPdo($baseDsn . ';dbname=' . $legacyDb);
    foreach ($chain as $mig) {
        if ($mig === '067_personel_canonical_reference_gate.sql') {
            continue; // boş test DB'sinde referans-veri kapısı (diğer runner'larla aynı)
        }
        $sql = (string) file_get_contents(__DIR__ . '/../../api/migrations/' . $mig);
        $pdo->exec($sql);
        if ($mig !== '100_user_yetki_istisnalari.sql') {
            $legacy->exec($sql);
        }
    }
    $one = static function (PDO $p, string $sql, array $a = []) {
        $s = $p->prepare($sql);
        $s->execute($a);

        return $s->fetchColumn();
    };

    // Kimlikler: 1=A, 6=B, 7=C (GY'ler). Not: 048 uq_users_actor_identity_id iki hesabın aynı kimliği paylaşmasını şema düzeyinde engeller.
    $pdo->exec("INSERT INTO actor_identities (id, identity_code, display_name, normalized_name, status, verification_source)
                VALUES (1,'K-A','A','a','VERIFIED','HUMAN_CONFIRMED'),(2,'K-B','B','b','VERIFIED','HUMAN_CONFIRMED'),(3,'K-C','C','c','VERIFIED','HUMAN_CONFIRMED')");
    $hash = password_hash('YwSeedPass-24chars!!', PASSWORD_BCRYPT);
    $ins = $pdo->prepare("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, actor_identity_id) VALUES (?, ?, '$hash', ?, ?, 'AKTIF', ?)");
    foreach ([[1, 'gyA', 'GENEL_YONETICI', 1], [6, 'gyB', 'GENEL_YONETICI', 2], [7, 'gyC', 'GENEL_YONETICI', 3],
              [2, 'sy', 'SISTEM_YONETICISI', null], [4, 'muh', 'MUHASEBE', null], [5, 'ikp', 'IK_PERSONELI', null], [8, 'muh2', 'MUHASEBE', null]] as [$id, $u, $r, $k]) {
        $ins->execute([$id, $u, 'Ad ' . $u, $r, $k]);
    }
    $actor = static fn (int $id) => ['id' => $id, 'rol' => (string) $one($pdo, 'SELECT rol FROM users WHERE id=?', [$id]), 'yetki_istisnalari' => S::loadActive($pdo, $id)];
    $eff = static fn (int $id, string $p) => R::resolve($actor($id), $p);
    $disi = static function (string $rol, int $atla = 0): string {
        foreach (\Medisa\Api\Auth\RolePermissions::permissionCatalog() as $p) {
            if (!in_array($p, R::NON_GRANTABLE_PERMISSIONS, true) && !in_array($p, \Medisa\Api\Auth\RolePermissions::roleDefaultPermissions($rol), true)
                && !\Medisa\Api\Auth\RolePermissions::isQrSelfServicePermission($p) && $atla-- <= 0) {
                return $p;
            }
        }
        throw new RuntimeException('izin yok');
    };
    $gecerli = ['permission' => 'personeller.create', 'etki' => 'ALLOW', 'gerekce' => 'test'];

    // --- yetki kapısı ve kendine yasak -------------------------------------------------
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(2), 4, $gecerli)) === 'FORBIDDEN', 'yetki yöneticisi olmayan (SISTEM_YONETICISI) yetki veremez');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 1, ['permission' => 'personeller.create', 'etki' => 'DENY', 'gerekce' => 'x'])) === Y::CODE_KENDI, 'kendine yetki/kısıt değiştirilemez');

    // --- yalnız global: şube istisnası kapalı ---------------------------------------
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 4, $gecerli + ['sube_id' => 1])) === Y::CODE_SUBE_KAPALI, 'şube kapsamlı istisna reddedilir (yalnız global)');
    ywAssert((int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE sube_id IS NOT NULL') === 0, 'şube kapsamlı satır yazılmadı');

    // --- girdi / kırmızı liste ---------------------------------------------------------
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 4, ['permission' => 'yok.boyle', 'etki' => 'ALLOW', 'gerekce' => 'x'])) === 'VALIDATION_ERROR', 'bilinmeyen izin reddedilir');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 4, ['permission' => 'personeller.create', 'etki' => 'ALLOW', 'gerekce' => ' '])) === 'VALIDATION_ERROR', 'gerekçe zorunlu');
    foreach (R::NON_GRANTABLE_PERMISSIONS as $p) {
        if (in_array($p, \Medisa\Api\Auth\RolePermissions::permissionCatalog(), true)) {
            ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 4, ['permission' => $p, 'etki' => 'ALLOW', 'gerekce' => 'x'])) === Y::CODE_VERILEMEZ, 'kırmızı liste ALLOW reddi: ' . $p);
        }
    }
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 6, ['permission' => 'kullanici_yetkileri.manage', 'etki' => 'DENY', 'gerekce' => 'x'])) === Y::CODE_KISITLANAMAZ, 'GY sistem hakkı DENY reddi (yetenek tabanlı son yönetici korunur)');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 6, ['permission' => 'personeller.create', 'etki' => 'ALLOW', 'gerekce' => 'x'])) === Y::CODE_GEREKSIZ, 'rolde zaten olan ALLOW reddi');

    // --- süre (karar 5) ---------------------------------------------------------------
    $now = time();
    $g = static fn (int $gun) => gmdate('Y-m-d H:i:s', $now + $gun * 86400);
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 4, $gecerli + ['gecerlilik_bitis' => $g(366)])) === Y::CODE_SURE, 'süreli ALLOW > 365 gün reddedilir');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 4, $gecerli + ['gecerlilik_bitis' => $g(-1)])) === Y::CODE_SURE, 'bitiş başlangıçtan önce reddedilir');
    $r = ywTry(fn () => Y::ver($pdo, $actor(1), 4, $gecerli + ['gecerlilik_bitis' => $g(364)]));
    ywAssert(str_starts_with($r, 'OK:') && $eff(4, 'personeller.create'), 'süreli ALLOW (≤365 gün) verilir ve etkin olur');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(6), 4, $gecerli)) === Y::CODE_ZATEN, 'aynı etkin istisna ikinci kez verilemez');
    $r2 = ywTry(fn () => Y::ver($pdo, $actor(1), 5, ['permission' => $disi('IK_PERSONELI'), 'etki' => 'ALLOW', 'gerekce' => 'kalıcı']));
    ywAssert(str_starts_with($r2, 'OK:') && $one($pdo, "SELECT gecerlilik_bitis FROM user_yetki_istisnalari WHERE user_id=5") === null, 'kalıcı ALLOW (bitiş boş) verilir');

    // --- audit: her yazma tek satır; K5 yok (GY olmayan hedef) --------------------------
    ywAssert((int) $one($pdo, "SELECT COUNT(*) FROM user_yetki_auditleri WHERE aksiyon='VER'") === 2, 'audit: her başarılı yazma tek VER satırı');
    ywAssert((int) $one($pdo, "SELECT COUNT(*) FROM personel_inbox_notifications WHERE kind='GY_YETKI_DEGISIKLIGI'") === 0, 'K5: GY olmayan hedefte bildirim yok, yalnız audit');

    // --- K1 UYARI (Medisa: politika satırı yok) ---------------------------------------
    $pdo->exec('UPDATE users SET actor_identity_id = NULL WHERE id = 7');
    $r = ywTry(fn () => Y::ver($pdo, $actor(7), 8, ['permission' => 'personeller.create', 'etki' => 'ALLOW', 'gerekce' => 'x']));
    ywAssert(str_starts_with($r, 'OK:') && str_contains($r, 'YETKI_VEREN_KIMLIK_DOGRULANMAMIS')
        && (string) $one($pdo, 'SELECT uyari_kodlari FROM user_yetki_auditleri ORDER BY id DESC LIMIT 1') === 'YETKI_VEREN_KIMLIK_DOGRULANMAMIS', 'K1 UYARI (Medisa): kimliksiz GY kilitlenmez, uyarı audit\'te');
    $pdo->exec('UPDATE users SET actor_identity_id = 3 WHERE id = 7');

    // --- GY hedef: herhangi bir GY kısıt koyar/kaldırır; K5 diğer GY'lere ----------------
    $r = ywTry(fn () => Y::ver($pdo, $actor(1), 6, ['permission' => 'personeller.create', 'etki' => 'DENY', 'gerekce' => 'kısıt']));
    $denyId = (int) json_decode(substr($r, 3), true)['istisna_id'];
    ywAssert(!$eff(6, 'personeller.create'), 'GY A, GY B\'ye kısıt koyar (etkin)');
    ywAssert((int) $one($pdo, "SELECT COUNT(*) FROM personel_inbox_notifications WHERE kind='GY_YETKI_DEGISIKLIGI' AND recipient_user_id=7") === 1
        && (int) $one($pdo, "SELECT COUNT(*) FROM personel_inbox_notifications WHERE kind='GY_YETKI_DEGISIKLIGI' AND recipient_user_id IN (1,6)") === 0, 'K5: bildirim yalnız diğer GY\'ye (aktöre ve hedefe değil)');
    ywAssert(ywTry(fn () => Y::iptal($pdo, $actor(6), 6, $denyId, 'kendim')) === Y::CODE_KENDI, 'hedef kendi kısıtını kaldıramaz');
    $pdo->exec("UPDATE users SET durum='PASIF' WHERE id=1");
    ywAssert(str_starts_with(ywTry(fn () => Y::iptal($pdo, $actor(7), 6, $denyId, 'kaldır')), 'OK:') && $eff(6, 'personeller.create'), 'koyan pasif olsa da başka GY kısıtı kaldırabilir');
    $pdo->exec("UPDATE users SET durum='AKTIF' WHERE id=1");
    ywAssert(ywTry(fn () => Y::iptal($pdo, $actor(7), 6, $denyId, 'tekrar')) === 'YETKI_ISTISNASI_ZATEN_IPTAL', 'iptal edilen istisna ikinci kez iptal edilemez');

    // --- K3: atayan ek yetki veremez (uygun başka GY varken); yoksa verebilir + uyarı -------
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET rol='GENEL_YONETICI' WHERE id=2");
    Y::rolDegisti($pdo, $actor(1), 2, 'SISTEM_YONETICISI', 'GENEL_YONETICI');
    $pdo->commit();
    ywAssert((int) $one($pdo, "SELECT aktor_user_id FROM user_yetki_auditleri WHERE aksiyon='PROFIL_DEGISTI' AND hedef_user_id=2") === 1, 'GY ataması atayanla audit\'e yazılır (PROFIL_DEGISTI)');
    // GY'de olmayan bir izin bul
    $gyDisi = null;
    foreach (\Medisa\Api\Auth\RolePermissions::permissionCatalog() as $p) {
        if (!in_array($p, R::NON_GRANTABLE_PERMISSIONS, true) && !in_array($p, \Medisa\Api\Auth\RolePermissions::roleDefaultPermissions('GENEL_YONETICI'), true)
            && !\Medisa\Api\Auth\RolePermissions::isQrSelfServicePermission($p)) {
            $gyDisi = $p;
            break;
        }
    }
    ywAssert($gyDisi !== null, 'K3 testi için GY dışı izin bulundu: ' . $gyDisi);
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(1), 2, ['permission' => $gyDisi, 'etki' => 'ALLOW', 'gerekce' => 'x'])) === Y::CODE_ATAYAN, 'K3: uygun başka GY varken atayan ALLOW veremez');
    ywAssert(str_starts_with(ywTry(fn () => Y::ver($pdo, $actor(6), 2, ['permission' => $gyDisi, 'etki' => 'ALLOW', 'gerekce' => 'x'])), 'OK:'), 'K3: başka GY verebilir');
    $pdo->exec("UPDATE users SET durum='PASIF' WHERE id IN (6,7)");
    $r = ywTry(fn () => Y::iptal($pdo, $actor(1), 2, (int) $one($pdo, "SELECT id FROM user_yetki_istisnalari WHERE user_id=2 AND iptal_edildi_at IS NULL"), 'x'));
    ywAssert(str_starts_with($r, 'OK:'), 'K3 daraltma (ALLOW iptali) atayana açık');
    $r = ywTry(fn () => Y::ver($pdo, $actor(1), 2, ['permission' => $gyDisi, 'etki' => 'ALLOW', 'gerekce' => 'tek']));
    ywAssert(str_starts_with($r, 'OK:') && str_contains($r, Y::UYARI_K3), 'K3: uygun başka GY yokken atayan verir + K3_ISTISNA_TEK_YONETICI (iki yöneticili kurulum kilitlenmez)');
    $pdo->exec("UPDATE users SET durum='AKTIF' WHERE id IN (6,7)");

    // --- karar 8: rol değişince aynı transaction'da iptal + audit ------------------------
    $aktifOnce = (int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=4 AND iptal_edildi_at IS NULL');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET rol='IK_PERSONELI' WHERE id=4");
    $n = Y::rolDegisti($pdo, $actor(1), 4, 'MUHASEBE', 'IK_PERSONELI');
    $pdo->rollBack();
    ywAssert($n === $aktifOnce && (int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=4 AND iptal_edildi_at IS NULL') === $aktifOnce, 'rol değişikliği geri alınırsa istisna iptali de geri alınır (aynı transaction)');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET rol='IK_PERSONELI' WHERE id=4");
    Y::rolDegisti($pdo, $actor(1), 4, 'MUHASEBE', 'IK_PERSONELI');
    $pdo->commit();
    ywAssert((int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=4 AND iptal_edildi_at IS NULL') === 0
        && (int) $one($pdo, "SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=4 AND iptal_nedeni='ROL_DEGISTI'") === $aktifOnce
        && (int) $one($pdo, "SELECT COUNT(*) FROM user_yetki_auditleri WHERE hedef_user_id=4 AND aksiyon='ROL_DEGISTI_IPTAL'") === $aktifOnce, 'rol değişince istisnalar ROL_DEGISTI ile iptal + her biri audit');

    // --- Kalıcı Sil ile yarış: hedef satırı FOR UPDATE ---------------------------------
    $pdo2 = ywPdo($baseDsn . ';dbname=' . $db);
    $pdo2->beginTransaction();
    $pdo2->query('SELECT id FROM users WHERE id = 5 FOR UPDATE')->fetchAll(); // Kalıcı Sil'in hedef kilidi
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $bekledi = false;
    try {
        Y::ver($pdo, $actor(1), 5, ['permission' => $disi('IK_PERSONELI', 1), 'etki' => 'ALLOW', 'gerekce' => 'yarış']);
    } catch (PDOException $e) {
        $bekledi = (int) ($e->errorInfo[1] ?? 0) === 1205;
    }
    $pdo2->rollBack();
    ywAssert($bekledi && !$pdo->inTransaction() && (int) $one($pdo, "SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=5 AND permission=?", [$disi('IK_PERSONELI', 1)]) === 0, 'Kalıcı Sil hedefi kilitliyken yetki yazımı bekler ve yarım kayıt bırakmaz');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');

    // --- K1 ZORUNLU (yeni kurulum benzeri) ---------------------------------------------
    $pdo->exec("INSERT INTO yetki_politikasi (anahtar, deger, kaynak) VALUES ('K1_KIMLIK_MODU','ZORUNLU','TEST')");
    $pdo->exec('UPDATE users SET actor_identity_id = NULL WHERE id = 7');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(7), 5, ['permission' => $disi('IK_PERSONELI', 1), 'etki' => 'ALLOW', 'gerekce' => 'x'])) === 'YETKI_VEREN_KIMLIK_DOGRULANMAMIS', 'K1 ZORUNLU: kimliksiz GY yetki veremez');
    ywAssert(str_starts_with(ywTry(fn () => Y::ver($pdo, $actor(1), 5, ['permission' => $disi('IK_PERSONELI', 1), 'etki' => 'ALLOW', 'gerekce' => 'x'])), 'OK:'), 'K1 ZORUNLU: doğrulanmış kimlikli GY verebilir');
    $pdo->exec('UPDATE users SET actor_identity_id = 3 WHERE id = 7');
    $pdo->exec("DELETE FROM yetki_politikasi WHERE anahtar='K1_KIMLIK_MODU'");

    // --- geri uyumluluk: 100 yoksa yazma 503, rol değişikliği no-op --------------------
    $legacy->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (1,'g','x','G','GENEL_YONETICI','AKTIF'),(4,'m','x','M','MUHASEBE','AKTIF')");
    ywAssert(ywTry(fn () => Y::ver($legacy, ['id' => 1, 'rol' => 'GENEL_YONETICI'], 4, $gecerli)) === Y::CODE_SEMA, '100 uygulanmamış: yazma 503 YETKI_SEMASI_HAZIR_DEGIL');
    $legacy->beginTransaction();
    ywAssert(Y::rolDegisti($legacy, ['id' => 1], 4, 'MUHASEBE', 'GENEL_YONETICI') === 0, '100 uygulanmamış: rol değişikliği yolu değişmez (no-op)');
    $legacy->rollBack();

    echo "verify-yetki-yazma-mysql: OK\n";
} finally {
    $pdo = null;
    $pdo2 = null;
    $legacy = null;
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $legacyDb . '`');
}
