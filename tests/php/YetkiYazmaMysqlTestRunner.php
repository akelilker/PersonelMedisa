<?php

declare(strict_types=1);

/**
 * Dinamik yetki P3 — yazma kuralları, gerçek tek kullanımlık MariaDB. Canlıya dokunmaz.
 * php tests/php/YetkiYazmaMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\EffectivePermissionResolver as R;
use Medisa\Api\Database\UserYetkiIstisnaSchema as S;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\ResponseCaptured;
use Medisa\Api\Services\Auth\KullaniciYetkiYazmaService as Y;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService as Onboard;
use Medisa\Api\Services\Auth\YetkiKimlikPolitikasi as K1;
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

/** @return array<string, mixed> */
function ywCapture(callable $fn): array
{
    JsonResponse::beginCapture();
    try {
        $result = $fn();
        $captured = JsonResponse::capturedResponse();
        if (is_array($captured)) {
            return $captured;
        }

        return ['data' => $result, 'meta' => [], 'errors' => []];
    } catch (ResponseCaptured $e) {
        $captured = JsonResponse::capturedResponse();

        return is_array($captured) ? $captured : [];
    } finally {
        JsonResponse::endCapture();
    }
}

function ywHata(array $envelope): string
{
    $code = $envelope['errors'][0]['code'] ?? '';

    return is_string($code) ? $code : '';
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
    $bild = static fn (int $uid) => (int) $one($pdo, "SELECT COUNT(*) FROM personel_inbox_notifications WHERE kind='GY_YETKI_DEGISIKLIGI' AND recipient_user_id=?", [$uid]);
    ywAssert($bild(6) === 1 && $bild(7) === 1 && $bild(1) === 0, 'K5 üç GY: hedef GY ve diğer aktif GY bildirim alır, aktör almaz');
    ywAssert((int) $one($pdo, "SELECT COUNT(*) FROM personel_inbox_notifications WHERE kind='GY_YETKI_DEGISIKLIGI' AND personel_id IS NOT NULL") === 0
        && (int) $one($pdo, 'SELECT COUNT(*) FROM users u LEFT JOIN personeller p ON p.id = u.personel_id WHERE u.id = 6 AND p.id IS NULL') === 1, 'K5: personel kaydı olmayan GY\'ye kullanıcı bazlı bildirim (personel_id NULL)');
    $liste = \Medisa\Api\Services\SelfService\PersonelInboxNotificationService::listForUser($pdo, 6, 50);
    ywAssert(count($liste) === 1 && $liste[0]['kind'] === 'GY_YETKI_DEGISIKLIGI'
        && (R::resolve(['id' => 6, 'rol' => 'GENEL_YONETICI'], 'attendance.correction.decide') || R::resolve(['id' => 6, 'rol' => 'GENEL_YONETICI'], 'puantaj.view')), 'K5: personelsiz GY bildirimi /me/inbox-notifications okuma yolundan görür (yetki kapısı geçer)');
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
    $RP = \Medisa\Api\Auth\RolePermissions::class;
    $yazma = $RP::genelYoneticiOperasyonelYazmaIzinleri();
    $varsayilan = $RP::roleDefaultPermissions('GENEL_YONETICI');
    $sinifDisi = [];
    $sizma = [];
    $mevcutKayip = [];
    foreach ($varsayilan as $p) {
        $yazmaMi = in_array($p, $yazma, true);
        $korunan = in_array($p, R::GY_NON_DENYABLE_PERMISSIONS, true) || $RP::isGenelYoneticiOkumaIzni($p);
        if ($yazmaMi === $korunan) {
            $sinifDisi[] = $p;
        }
        if ($yazmaMi && $eff(2, $p)) {
            $sizma[] = $p;
        }
        if (!$yazmaMi && !$eff(2, $p)) {
            $sizma[] = 'korunan-kapali:' . $p;
        }
        if ($yazmaMi && !$eff(1, $p)) {
            $mevcutKayip[] = $p;
        }
    }
    require_once __DIR__ . '/../../api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php';
    ywAssert($sinifDisi === [] && $sizma === [] && $mevcutKayip === []
        && !$eff(2, 'personeller.update') && !$eff(2, 'personeller.ucret.manage') && !$eff(2, 'yillik_izin_hak_duzeltme.manage')
        && !$eff(2, 'bordro_kesinlestirme.approve') && !$eff(2, 'sgk_karar_paketi.approve')
        && $eff(2, 'personeller.view') && $eff(2, 'kullanici_yetkileri.manage') && $eff(2, 'yonetim-paneli.manage')
        && $eff(1, 'personeller.update') && $eff(6, 'bordro_kesinlestirme.approve')
        && \Medisa\Api\Services\Personel\RolePermissionsBridge::canWritePersonel($actor(1)) === true
        && \Medisa\Api\Services\Personel\RolePermissionsBridge::canWritePersonel($actor(2)) === false
        && str_contains((string) $one($pdo, "SELECT sonraki_json FROM user_yetki_auditleri WHERE aksiyon='PROFIL_DEGISTI' AND hedef_user_id=2"), 'KISITLI'),
        'P4: mevcut GY tam kalır; yeni GY operasyonel yazamaz, okur ve yetki yönetir. Sapma: ' . implode(',', array_merge($sinifDisi, $sizma, $mevcutKayip)));
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
    $r = ywTry(fn () => Y::iptal($pdo, $actor(1), 2, (int) $one($pdo, "SELECT id FROM user_yetki_istisnalari WHERE user_id=2 AND etki='ALLOW' AND iptal_edildi_at IS NULL ORDER BY id DESC LIMIT 1"), 'x'));
    ywAssert(str_starts_with($r, 'OK:'), 'K3 daraltma (ALLOW iptali) atayana açık');
    $r = ywTry(fn () => Y::ver($pdo, $actor(1), 2, ['permission' => $gyDisi, 'etki' => 'ALLOW', 'gerekce' => 'tek']));
    ywAssert(str_starts_with($r, 'OK:') && str_contains($r, Y::UYARI_K3), 'K3: uygun başka GY yokken atayan verir + K3_ISTISNA_TEK_YONETICI (iki yöneticili kurulum kilitlenmez)');
    // iki GY'li kurulum (1 aktör + 2 hedef; 6,7 pasif): hedef bildirim alır
    $aid = (int) json_decode(substr($r, 3), true)['audit_id'];
    $alicilar = array_map('intval', $pdo->query("SELECT recipient_user_id FROM personel_inbox_notifications WHERE kind='GY_YETKI_DEGISIKLIGI' AND JSON_EXTRACT(payload_json, '$.yetki_audit_id') = $aid ORDER BY recipient_user_id")->fetchAll(PDO::FETCH_COLUMN));
    ywAssert($alicilar === [2], 'K5 iki GY: aktör + hedef → yalnız hedef GY bildirim alır, aktör almaz');
    $pdo->exec("UPDATE users SET durum='AKTIF' WHERE id IN (6,7)");

    // --- P4: ek izin, kendi yetkisini genişletememe, rol değişince yeniden kısıt -------
    $denyUpdate = (int) $one($pdo, "SELECT id FROM user_yetki_istisnalari WHERE user_id=2 AND permission='personeller.update' AND etki='DENY' AND iptal_edildi_at IS NULL");
    ywAssert($denyUpdate > 0, 'P4: yeni GY personel düzenleme kısıtı kayıtlı');
    ywAssert(ywTry(fn () => Y::iptal($pdo, $actor(2), 2, $denyUpdate, 'kendim acayim')) === Y::CODE_KENDI, 'P4: yeni GY kendi operasyonel yetkisini genişletemez');
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(6), 2, ['permission' => 'personeller.update', 'etki' => 'ALLOW', 'gerekce' => 'ac'])) === Y::CODE_GEREKSIZ, 'P4: rol varsayılanındaki yazma ALLOW ile değil, kısıt kaldırılarak açılır');
    ywAssert(ywTry(fn () => Y::iptal($pdo, $actor(1), 2, $denyUpdate, 'atayan acsin')) === Y::CODE_ATAYAN, 'P4: atayan, başka GY varken yeni yöneticinin yetkisini genişletemez');
    ywAssert(str_starts_with(ywTry(fn () => Y::iptal($pdo, $actor(6), 2, $denyUpdate, 'ek izin')), 'OK:')
        && $eff(2, 'personeller.update') && !$eff(2, 'personeller.ucret.manage') && !$eff(2, 'yillik_izin_hak_duzeltme.manage'),
        'P4: başka GY ek operasyonel izin açar; açılmayan yazma kapalı kalır');
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET rol='MUHASEBE' WHERE id=2");
    Y::rolDegisti($pdo, $actor(1), 2, 'GENEL_YONETICI', 'MUHASEBE');
    $kapali = (int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=2 AND iptal_edildi_at IS NULL');
    $pdo->exec("UPDATE users SET rol='GENEL_YONETICI' WHERE id=2");
    Y::rolDegisti($pdo, $actor(1), 2, 'MUHASEBE', 'GENEL_YONETICI');
    $yeniden = (int) $one($pdo, "SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=2 AND etki='DENY' AND iptal_edildi_at IS NULL");
    ywAssert($kapali === 0 && $yeniden === count($yazma) && !$eff(2, 'personeller.update') && $eff(2, 'personeller.view'), 'P4: rol değişince eski istisnalar kapanır; yeniden GY ataması yine kısıtlı başlar');
    $pdo->rollBack();
    ywAssert($eff(2, 'personeller.update') === true, 'P4: rol değişikliği geri alınırsa açılmış ek izin durur');

    // --- P4: şirketin ilk Genel Yöneticisi tam yetkili (başka GY satırı yok) ----------
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET rol='MUHASEBE' WHERE rol='GENEL_YONETICI'");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (60, 'ilkGy', '$hash', 'Ilk GY', 'GENEL_YONETICI', 'AKTIF')");
    ywAssert(Y::rolDegisti($pdo, $actor(4), 60, '', 'GENEL_YONETICI') === 0, 'P4: ilk GY atamasında iptal edilecek eski istisna yok');
    $ilkJson = (string) $one($pdo, "SELECT sonraki_json FROM user_yetki_auditleri WHERE aksiyon='PROFIL_DEGISTI' AND hedef_user_id=60 ORDER BY id DESC LIMIT 1");
    ywAssert((int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=60 AND iptal_edildi_at IS NULL') === 0
        && str_contains($ilkJson, 'TAM')
        && $eff(60, 'personeller.update') && $eff(60, 'bordro_kesinlestirme.approve') && $eff(60, 'sgk_karar_paketi.approve')
        && $eff(60, 'kullanici_yetkileri.manage'),
        'P4: başka aktif GY yokken atama tam yetkili');
    $pdo->rollBack();

    // --- P4: pasif GY, tek aktif yöneticiyi kısıtlamaz --------------------------------
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET durum='PASIF' WHERE rol='GENEL_YONETICI'");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (61, 'tekAktif', '$hash', 'Tek Aktif', 'GENEL_YONETICI', 'AKTIF')");
    Y::rolDegisti($pdo, $actor(4), 61, 'MUHASEBE', 'GENEL_YONETICI');
    $pasifSay = (int) $one($pdo, "SELECT COUNT(*) FROM users WHERE rol='GENEL_YONETICI' AND durum='PASIF'");
    ywAssert($pasifSay > 0
        && (int) $one($pdo, 'SELECT COUNT(*) FROM user_yetki_istisnalari WHERE user_id=61 AND iptal_edildi_at IS NULL') === 0
        && str_contains((string) $one($pdo, "SELECT sonraki_json FROM user_yetki_auditleri WHERE aksiyon='PROFIL_DEGISTI' AND hedef_user_id=61 ORDER BY id DESC LIMIT 1"), 'TAM')
        && $eff(61, 'personeller.update'),
        'P4: yalnız pasif GY varken ilk aktif yönetici tam yetkili');
    $pdo->rollBack();

    // --- P4: şirket bağları GY'yi ayırmaz (rol şirket kapsamı alamaz) ----------------
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO sirketler (id, kod, ad, durum) VALUES (1, 'MEDISA', 'Medisa', 'AKTIF'), (2, 'DIGER', 'Diger Sirket', 'AKTIF')");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (62, 'digerIlk', '$hash', 'Diger Ilk', 'MUHASEBE', 'AKTIF')");
    $pdo->exec('INSERT INTO user_sirketler (user_id, sirket_id) VALUES (62, 2)');
    $pdo->exec("UPDATE users SET rol='GENEL_YONETICI' WHERE id=62");
    Y::rolDegisti($pdo, $actor(1), 62, 'MUHASEBE', 'GENEL_YONETICI');
    ywAssert(\Medisa\Api\Scope\OrgScope::isSirketScopeEligible('GENEL_YONETICI', [2]) === false
        && (int) $one($pdo, 'SELECT COUNT(*) FROM user_sirketler WHERE user_id=62 AND sirket_id=2') === 1
        && (int) $one($pdo, "SELECT COUNT(*) FROM users WHERE rol='GENEL_YONETICI' AND durum='AKTIF' AND id<>62") > 0
        && !$eff(62, 'personeller.update')
        && str_contains((string) $one($pdo, "SELECT sonraki_json FROM user_yetki_auditleri WHERE aksiyon='PROFIL_DEGISTI' AND hedef_user_id=62 ORDER BY id DESC LIMIT 1"), 'KISITLI'),
        'P4: başka şirkete bağlanan yeni GY, sistemde aktif GY varken kısıtlı başlar');
    $pdo->rollBack();

    // --- P4: kırmızı liste DENY iptaliyle açılır; ALLOW hâlâ yasak --------------------
    // ver/iptal kendi transaction'ını açar; dış transaction içinde çağrılmaz.
    $hassasAcildi = [];
    foreach (R::NON_GRANTABLE_PERMISSIONS as $hp) {
        if (!in_array($hp, $yazma, true)) {
            continue;
        }
        $hid = (int) $one($pdo, 'SELECT id FROM user_yetki_istisnalari WHERE user_id=2 AND permission=? AND etki=\'DENY\' AND iptal_edildi_at IS NULL', [$hp]);
        if ($hid <= 0 || $eff(2, $hp)) {
            $hassasAcildi[] = 'baslangic:' . $hp;
            continue;
        }
        if (ywTry(fn () => Y::ver($pdo, $actor(6), 2, ['permission' => $hp, 'etki' => 'ALLOW', 'gerekce' => 'hassas'])) !== Y::CODE_VERILEMEZ) {
            $hassasAcildi[] = 'allow:' . $hp;
        }
        if (!str_starts_with(ywTry(fn () => Y::iptal($pdo, $actor(6), 2, $hid, 'hassas ac')), 'OK:') || !$eff(2, $hp)) {
            $hassasAcildi[] = 'iptal:' . $hp;
        }
    }
    $ucretId = (int) $one($pdo, "SELECT id FROM user_yetki_istisnalari WHERE user_id=2 AND permission='personeller.ucret.manage' AND etki='DENY' AND iptal_edildi_at IS NULL");
    ywAssert($hassasAcildi === []
        && in_array('sgk_karar_paketi.approve', $yazma, true)
        && in_array('bordro_kesinlestirme.approve', $yazma, true)
        && $ucretId > 0
        && str_starts_with(ywTry(fn () => Y::iptal($pdo, $actor(6), 2, $ucretId, 'ucret ac')), 'OK:')
        && $eff(2, 'personeller.ucret.manage'),
        'P4: operasyonel yazma açılır; kırmızı liste ALLOW ile verilmez ama DENY iptaliyle açılır. Sapma: ' . implode(',', $hassasAcildi));

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

    // --- bir kişi, bir hesap (ek bağ tablosu yok) ------------------------------------
    $bagYok = false;
    try {
        $pdo->query('SELECT 1 FROM user_gercek_kisi_baglari LIMIT 0');
    } catch (PDOException $e) {
        $bagYok = (int) ($e->errorInfo[1] ?? 0) === 1146;
    }
    ywAssert($bagYok, 'ek hesap bağ tablosu yok');
    $kopyaKimlik = false;
    try {
        $pdo->exec('UPDATE users SET actor_identity_id = 1 WHERE id = 6');
    } catch (PDOException $e) {
        $kopyaKimlik = true;
    }
    ywAssert($kopyaKimlik && (int) $one($pdo, 'SELECT actor_identity_id FROM users WHERE id = 6') === 2, 'users.actor_identity_id 1:1 dokunulmadı (SGK/dört göz değişmez)');

    $belirsizIzin = $disi('MUHASEBE', 4);
    ywAssert(K1::ayniGuvenilirKisi($pdo, 7, 8) === false, 'personel kaydı olmayan ve kimliği bağlanmayan yönetici aynı kişi sayılmaz');
    $durum8 = (string) $one($pdo, "SELECT durum FROM users WHERE id = 8");
    ywAssert(str_starts_with(ywTry(fn () => Y::ver($pdo, $actor(7), 8, ['permission' => $belirsizIzin, 'etki' => 'ALLOW', 'gerekce' => 'farklı kişi'])), 'OK:')
        && (string) $one($pdo, 'SELECT durum FROM users WHERE id = 8') === $durum8, 'kimliği belirsiz eski hesap kilitlenmez ve yetki yazımı engellenmez');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
    $ywPersonel = static function (PDO $pdo, int $id, string $ad, string $soyad, string $tc) {
        $stmt = $pdo->prepare(
            'INSERT INTO personeller (
                id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
                acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
                sube_id, departman_id, gorev_id, aktif_durum, calisan_kapsami
             ) VALUES (:id, :tc, :ad, :soyad, :dogum, :tel, :ak, :aktel, :sicil, :ise, 1, 1, 1, :aktif, :kapsam)'
        );
        $stmt->execute([
            'id' => $id, 'tc' => $tc, 'ad' => $ad, 'soyad' => $soyad, 'dogum' => '1990-01-01',
            'tel' => '555000' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'ak' => 'A', 'aktel' => '555100' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'sicil' => 'SIC-' . $id, 'ise' => '2021-01-01', 'aktif' => 'AKTIF', 'kapsam' => 'IC_PERSONEL',
        ]);
    };
    $ywPersonel($pdo, 401, 'Berna', 'Kaya', '10000000401');
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id) VALUES (9, 'bernaK', '$hash', 'Berna Kaya', 'MUHASEBE', 'AKTIF', 401)");
    $pdo->exec('UPDATE actor_identities SET personel_id = 401 WHERE id = 2');
    ywAssert((int) $one($pdo, 'SELECT personel_id FROM users WHERE id = 6') === 0 && K1::ayniGuvenilirKisi($pdo, 6, 9), 'personel kaydı olmayan yöneticinin doğrulanmış kimliği aynı kişiyi gösterir');
    $ayniIzin = $disi('MUHASEBE', 5);
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(6), 9, ['permission' => $ayniIzin, 'etki' => 'ALLOW', 'gerekce' => 'kendi hesabım'])) === 'YETKI_AYNI_GERCEK_KISI', 'K1 UYARI: aynı kişinin hesabına yetki verme her durumda reddedilir');
    $kisit = \Medisa\Api\Auth\RolePermissions::roleDefaultPermissions('MUHASEBE')[0];
    $r = ywTry(fn () => Y::ver($pdo, $actor(1), 9, ['permission' => $kisit, 'etki' => 'DENY', 'gerekce' => 'kısıt']));
    ywAssert(str_starts_with($r, 'OK:'), 'farklı kişi kısıt koyabilir');
    $kisitId = (int) json_decode(substr($r, 3), true)['istisna_id'];
    ywAssert(ywTry(fn () => Y::iptal($pdo, $actor(6), 9, $kisitId, 'kendi kısıtım')) === 'YETKI_AYNI_GERCEK_KISI', 'K1 UYARI: aynı kişinin kısıtını kaldırma her durumda reddedilir');
    ywAssert(str_starts_with(ywTry(fn () => Y::iptal($pdo, $actor(7), 9, $kisitId, 'kaldır')), 'OK:'), 'farklı kişi kısıtı kaldırabilir');
    $pdo->exec("INSERT INTO yetki_politikasi (anahtar, deger, kaynak) VALUES ('K1_KIMLIK_MODU','ZORUNLU','TEST')");
    ywAssert(ywTry(fn () => Y::ver($pdo, $actor(6), 9, ['permission' => $disi('MUHASEBE', 6), 'etki' => 'ALLOW', 'gerekce' => 'x'])) === 'YETKI_AYNI_GERCEK_KISI', 'K1 ZORUNLU: aynı kişinin ikinci hesabına verme ENGELLENDİ');
    ywAssert(str_starts_with(ywTry(fn () => Y::ver($pdo, $actor(7), 9, ['permission' => $disi('MUHASEBE', 6), 'etki' => 'ALLOW', 'gerekce' => 'x'])), 'OK:'), 'K1 ZORUNLU: farklı kişi aynı hesaba verebilir');
    $pdo->exec("DELETE FROM yetki_politikasi WHERE anahtar='K1_KIMLIK_MODU'");

    $hesapSayisi = static fn () => (int) $one($pdo, 'SELECT COUNT(*) FROM users');
    $tcYok = static function (array $env, string $tc): bool {
        $yanit = json_encode($env, JSON_UNESCAPED_UNICODE);

        return is_string($yanit) && !str_contains($yanit, $tc);
    };
    $ywPersonel($pdo, 402, 'Pasif', 'Hesap', '10000000402');
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id) VALUES (20, 'pasifH', '$hash', 'Pasif Hesap', 'PERSONEL', 'PASIF', 402)");
    $once = $hesapSayisi();
    $pasifTc = '10000000402';
    $pasif = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 402, ['id' => 1]));
    ywAssert(ywHata($pasif) === Onboard::ERR_MEVCUT_PASIF_HESAP && $hesapSayisi() === $once
        && (string) $one($pdo, "SELECT durum FROM users WHERE id = 20") === 'PASIF'
        && ($pasif['meta']['mevcut_kullanici_adi'] ?? '') === 'pasifH'
        && $tcYok($pasif, $pasifTc), 'pasif hesap varken yeni hesap açılmaz; mevcut hesap incelenir; T.C. yanıtta yok');

    $ywPersonel($pdo, 403, 'Aktif', 'Hesap', '10000000403');
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id) VALUES (21, 'aktifH', '$hash', 'Aktif Hesap', 'PERSONEL', 'AKTIF', 403)");
    $once = $hesapSayisi();
    $mukerrer = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 403, ['id' => 1]));
    ywAssert(ywHata($mukerrer) === Onboard::ERR_ALREADY_PROVISIONED && $hesapSayisi() === $once, 'aynı personelin ikinci hesabı açılmaz');

    $ywPersonel($pdo, 404, 'Ali', 'Yilmaz', '10000000404');
    $ywPersonel($pdo, 405, 'Ali', 'Yilmaz', '10000000405');
    $birinci = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 404, ['id' => 1]));
    $carpisma = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 405, ['id' => 1]));
    $ikinciHesap = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 405, ['id' => 1], 'aliY2'));
    $idA = (int) ($birinci['data']['user']['id'] ?? 0);
    $idB = (int) ($ikinciHesap['data']['user']['id'] ?? 0);
    ywAssert(ywHata($birinci) === '' && ywHata($carpisma) === Onboard::ERR_USERNAME_COLLISION && $idA > 0 && $idB > 0 && $idA !== $idB
        && K1::ayniGuvenilirKisi($pdo, $idA, $idB) === false, 'aynı isimli farklı personel karıştırılmaz; ikisine de hesap açılır');

    $pdo->exec('ALTER TABLE personeller DROP INDEX uq_personeller_tc');
    $ayniTc = '10000000406';
    $ywPersonel($pdo, 406, 'Can', 'Demir', $ayniTc);
    $ywPersonel($pdo, 407, 'Caner', 'Demir', $ayniTc);
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id) VALUES (90, 'canD', '$hash', 'Can Demir', 'PERSONEL', 'AKTIF', 406)");
    $once = $hesapSayisi();
    $tcEngel = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 407, ['id' => 1]));
    ywAssert(ywHata($tcEngel) === Onboard::ERR_MUKERRER_KISI_HESABI && $hesapSayisi() === $once
        && (int) $one($pdo, 'SELECT id FROM users WHERE id = 90') === 90
        && $tcYok($tcEngel, $ayniTc), 'aynı T.C. ile ikinci hesap açılmaz; T.C. yanıtta yok; eski hesap birleştirilmez');

    $ywPersonel($pdo, 408, 'Yonetici', 'Kopru', '10000000408');
    $pdo->exec('UPDATE actor_identities SET personel_id = 408 WHERE id = 3');
    $once = $hesapSayisi();
    $kopru = ywCapture(fn () => Onboard::onboardAndIssue($pdo, 408, ['id' => 1]));
    ywAssert(ywHata($kopru) === Onboard::ERR_MUKERRER_KISI_HESABI && $hesapSayisi() === $once
        && (string) $one($pdo, "SELECT durum FROM users WHERE id = 7") === 'AKTIF'
        && $one($pdo, 'SELECT personel_id FROM users WHERE id = 7') === null, 'personelsiz yöneticinin doğrulanmış kimliği varsa ikinci personel hesabı açılmaz');

    // --- geri uyumluluk: 100 yoksa yazma 503, rol değişikliği no-op --------------------
    $legacy->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (1,'g','x','G','GENEL_YONETICI','AKTIF'),(4,'m','x','M','MUHASEBE','AKTIF')");
    ywAssert(ywTry(fn () => Y::ver($legacy, ['id' => 1, 'rol' => 'GENEL_YONETICI'], 4, $gecerli)) === Y::CODE_SEMA, '100 uygulanmamış: yazma 503 YETKI_SEMASI_HAZIR_DEGIL');
    $legacy->beginTransaction();
    ywAssert(Y::rolDegisti($legacy, ['id' => 1], 4, 'MUHASEBE', 'GENEL_YONETICI') === 0, '100 uygulanmamış: rol değişikliği yolu değişmez (no-op)');
    ywAssert(R::resolve(['id' => 1, 'rol' => 'GENEL_YONETICI'], 'personeller.update')
        && R::resolve(['id' => 1, 'rol' => 'GENEL_YONETICI'], 'bordro_kesinlestirme.approve')
        && R::resolve(['id' => 1, 'rol' => 'GENEL_YONETICI'], 'kullanici_yetkileri.manage'),
        '100 uygulanmamış: mevcut GY işlemleri rol varsayılanıyla sürer, kısıt uygulanmaz');
    $legacy->rollBack();

    echo "verify-yetki-yazma-mysql: OK\n";
} finally {
    $pdo = null;
    $pdo2 = null;
    $legacy = null;
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $legacyDb . '`');
}
