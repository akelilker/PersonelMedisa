<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use PDO;
use PDOException;

/**
 * Canonical owner for PERSONEL account onboarding.
 *
 * Yeni PERSONEL hesabi: canonical username + template baslangic sifresi + zorunlu ilk giris
 * sifre degisimi (`activation_required = 0`, `must_change_password = 1`). Bu yolda aktivasyon
 * daveti/linki URETILMEZ.
 *
 * Does not replace generic management-user creation.
 */
class PersonelAccountOnboardingService
{
    public const EVENT_ACCOUNT_CREATED = 'PERSONEL_ACCOUNT_CREATED';
    public const EVENT_ACCOUNT_BOUND = 'PERSONEL_ACCOUNT_BOUND';
    /** Canonical first-login credential gecisi (username + template sifre hash + zorunlu degisim). */
    public const EVENT_FIRST_LOGIN_CREDENTIALS_APPLIED = 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED';
    /**
     * Rollout seviyesinde TEK SEFERLIK ledger izi (user_id ve personel_id NULL). Cohort'un
     * tamami bir kez uygulandigini kanitlar; apply yolu bu kaydi gorunce fail-closed durur.
     * Boyutce: rollout basina tek satir; secret tasimaz (yalniz fingerprint + sayilar).
     */
    public const EVENT_FIRST_LOGIN_ROLLOUT_APPLIED = 'PERSONEL_FIRST_LOGIN_ROLLOUT_APPLIED';

    /**
     * Yeni hesap create yolunun canonical credential modeli (secret tasimaz):
     * template baslangic sifresi + zorunlu ilk giris sifre degisimi.
     */
    public const CREDENTIAL_MODEL_FIRST_LOGIN = 'FIRST_LOGIN_TEMPLATE';

    /**
     * Canonical first-login gecisinde mutation cohort'undan HARIC tutulan rezerve kullanici adlari.
     * Bu hesaplarin username / password_hash / rol / durum / personel_id / scope alanlari degismez.
     */
    public const PROTECTED_USERNAMES = ['ilkerA'];

    /**
     * Bu first-login credential rollout'unun kapsami DISINDA tutulan personel id'leri.
     *
     * 219 (doguA / Dogu Berkan Atmaca) henuz PERSONEL hesabi DEGILDIR ve bu rollout'a
     * DAHIL EDILMEZ; kendi ayri canonical onboarding operasyonu ile acilacaktir. Hesap
     * bir gun once acilirsa plana girer ve apply fail-closed durur: bu liste sessizce
     * genisleyen bir cohort degil, kilitli bir kapsam siniridir.
     */
    public const ROLLOUT_EXCLUDED_PERSONEL_IDS = [219];

    /**
     * Explicit business override'lari (business karari; generic kuralin ONUNDE gelir).
     * Key = personel_id. Map'te olmayan hesaplar generic canonical kurala tabidir.
     *
     * - 108 / 109: generic kural iki hesap icin de `hakanA` uretiyordu; cakismayi cozmek icin
     *   her hesaba ayri deterministic kullanici adi verildi. `hakanA` artik kullanilmaz.
     * - 206: kayitli ad/soyad yalniz "ABDULLAH"; soyad UYDURULMAZ ve personeller.soyad alanina
     *   deger yazilmaz. Bu hesap generic soyad kuralinin explicit exception'idir.
     *
     * `initial_password` yalniz generic kuralin uretemedigi exception icin tanimlidir; diger
     * hesaplarin sifre materyali canonical soyad kuralindan (soyad ASCII TitleCase + "123")
     * uretilir. Plaintext hicbir yerde saklanmaz; yalnizca PasswordHasher ile hash'lenir.
     */
    public const PERSONEL_CREDENTIAL_OVERRIDES = [
        108 => ['username' => 'hakanAc'],
        109 => ['username' => 'hakanAt'],
        206 => ['username' => 'abdullah', 'initial_password' => 'Abdullah123'],
    ];

    /**
     * Personel master data (yalniz `ad` / `soyad`) business correction plan'i — first-login rollout.
     * Key = personel_id. `from` exact preimage'dir: apply aninda satir bu degerle uyusmuyorsa
     * fail-closed davranilir ve hicbir satir yazilmaz. `to` canonical username/sifre kuralinin
     * dayanagidir. 206 bu map'te YOKTUR; o hesabin soyad alani degistirilmez.
     * Ad/soyad disinda personel alani degismez.
     */
    public const PERSONEL_NAME_CORRECTIONS = [
        200 => [
            'from' => ['ad' => 'RAED FAWAZ', 'soyad' => null],
            'to' => ['ad' => 'Raed', 'soyad' => 'Fawaz'],
        ],
        201 => [
            'from' => ['ad' => 'SAIF TAREQ JASIM AL-GBURI', 'soyad' => null],
            'to' => ['ad' => 'Saif Tareq Jasim', 'soyad' => 'Al-Gburi'],
        ],
        207 => [
            'from' => ['ad' => 'OKTAY ERSÖZ', 'soyad' => null],
            'to' => ['ad' => 'Oktay', 'soyad' => 'Ersöz'],
        ],
        209 => [
            'from' => ['ad' => 'MUQTADA MAZIN KHALEE', 'soyad' => null],
            'to' => ['ad' => 'Muqtada Mazin', 'soyad' => 'Khalee'],
        ],
        210 => [
            'from' => ['ad' => 'FAHRİ TAYLAN MERCAN', 'soyad' => null],
            'to' => ['ad' => 'Fahri Taylan', 'soyad' => 'Mercan'],
        ],
        // 219: kayitli bolunme yanlistir (ad = tek kelime, soyad = iki kelime).
        // Kilitli kural son kelimeyi soyad, onceki tum kelimeleri ad yapar; boylece
        // canonical username `doguB` yerine `doguA` ve sifre `Atmaca123` olur.
        // Bu personelin henuz PERSONEL hesabi YOKTUR; correction ancak hesap
        // acildiginda plan'a girer ve preimage guard'i ile uygulanir.
        219 => [
            'from' => ['ad' => 'DOĞU', 'soyad' => 'BERKAN ATMACA'],
            'to' => ['ad' => 'Doğu Berkan', 'soyad' => 'Atmaca'],
        ],
    ];

    public const ERR_NAME_REQUIRED = 'PERSONEL_NAME_REQUIRED_FOR_ACCOUNT';
    public const ERR_USERNAME_COLLISION = 'PERSONEL_USERNAME_COLLISION';
    public const ERR_USERNAME_INVALID = 'PERSONEL_USERNAME_INVALID';
    public const ERR_ALREADY_PROVISIONED = 'ALREADY_PROVISIONED';
    public const ERR_PERSONEL_INACTIVE = 'PERSONEL_INACTIVE';
    public const ERR_PERSONEL_ARCHIVED = 'PERSONEL_ARCHIVED_FIXTURE';
    public const ERR_USE_SECURE = 'PERSONEL_USE_SECURE_ONBOARDING';
    public const ERR_SCHEMA = 'SCHEMA_NOT_READY';
    /** Collision halinde hicbir mutation yapilmaz; blocker cagirana raporlanir. */
    public const ERR_CANONICAL_USERNAME_COLLISION = 'PERSONEL_CANONICAL_USERNAME_COLLISION';
    /** Name correction preimage uyusmazsa hicbir mutation yapilmaz (fail-closed). */
    public const ERR_NAME_CORRECTION_PREIMAGE = 'PERSONEL_NAME_CORRECTION_PREIMAGE_MISMATCH';
    /**
     * Apply, cagiranin pinledigi plan fingerprint'i ile yeniden uretilen plan uyusmazsa
     * hicbir mutation yapilmaz. Cohort drift / SHA drift apply'i fail-closed durdurur.
     */
    public const ERR_PLAN_FINGERPRINT_MISMATCH = 'PERSONEL_FIRST_LOGIN_PLAN_FINGERPRINT_MISMATCH';
    /**
     * Bu rollout daha once uygulanmissa ikinci kez calistirilmaz. Replay, ilk girisinden
     * sonra sifresini degistirmis bir kullanicinin template sifresini geri yuklememelidir.
     */
    public const ERR_ROLLOUT_ALREADY_APPLIED = 'PERSONEL_FIRST_LOGIN_ROLLOUT_ALREADY_APPLIED';
    /**
     * ROLLOUT_EXCLUDED_PERSONEL_IDS icindeki bir personel plana girerse cohort fail-closed durur.
     */
    public const ERR_ROLLOUT_SCOPE_VIOLATION = 'PERSONEL_FIRST_LOGIN_ROLLOUT_SCOPE_VIOLATION';

    /**
     * Personel icin canonical first-login modelinde hesap olusturur ve `personel_id` ile baglar.
     *
     * Uretilen state:
     *   username             = explicit business override > canonical ad/soyad kurali
     *   password_hash        = PasswordHasher::hash(template sifre materyali)
     *   activation_required  = 0
     *   must_change_password = 1
     *
     * Ayrica: otomatik sayi suffix'i yok; cakismada yetkilinin verdigi `username` override'i
     * kullanilir. Bu yolda aktivasyon daveti/linki uretilmez. Plaintext sifre DB'ye yazilmaz,
     * loglanmaz, audit'e konmaz ve response'ta donmez.
     *
     * @param array<string, mixed> $actorUser
     * @param string|null $usernameOverride Çakışma durumunda yetkili tarafından verilen alternatif kullanıcı adı
     * @return array<string, mixed>
     */
    public static function onboardAndIssue(PDO $pdo, $personelId, array $actorUser, $usernameOverride = null)
    {
        self::assertSchemaReady($pdo);
        $personelId = (int) $personelId;
        $actorId = isset($actorUser['id']) ? (int) $actorUser['id'] : 0;
        if ($personelId <= 0 || $actorId <= 0) {
            JsonResponse::badRequest('Gecersiz istek.', 'VALIDATION_ERROR');
        }

        $pdo->beginTransaction();
        try {
            $personel = self::lockPersonelRow($pdo, $personelId);
            self::assertEligibleForOnboarding($pdo, $personel);

            $ad = $personel['ad'] ?? null;
            $soyad = $personel['soyad'] ?? null;
            // Canonical username owner'i: override > name correction > kayitli ad/soyad.
            $canonicalUsername = self::resolvePersonelCanonicalUsername($personelId, $ad, $soyad);
            if ($canonicalUsername === null) {
                JsonResponse::badRequest(
                    'Personel hesabi icin gecerli ad ve soyad zorunludur.',
                    self::ERR_NAME_REQUIRED,
                    'ad'
                );
            }
            $suggested = $canonicalUsername;
            $username = $usernameOverride !== null && trim((string) $usernameOverride) !== ''
                ? self::normalizeOverrideUsername($usernameOverride)
                : $suggested;
            $usernameOverridden = $username !== $suggested;

            $existing = self::findBoundUserForPersonel($pdo, $personelId);

            if ($existing !== null) {
                $pdo->commit();
                JsonResponse::error(
                    409,
                    self::ERR_ALREADY_PROVISIONED,
                    'Bu personelin hesabi zaten mevcut.',
                    'personel_id'
                );
            }

            // Business name correction (varsa) hesap create ile AYNI transaction icinde uygulanir.
            // Correction yoksa kayitli ad/soyad aynen kalir; mevcut onboarding davranisi degismez.
            // Exact preimage uyusmazsa hicbir mutation yapilmadan fail-closed doner.
            $nameCorrection = self::applyOnboardingNameCorrection($pdo, $personel);
            $ad = $nameCorrection['ad'];
            $soyad = $nameCorrection['soyad'];

            self::assertUsernameAvailableForNewAccount($pdo, $username, $personelId, $suggested);

            $adSoyad = trim((string) $ad . ' ' . (string) $soyad);
            if ($adSoyad === '') {
                $adSoyad = $username;
            }

            // Template sifre materyali yalniz bellekte tutulur; hemen hash'lenir ve plaintext silinir.
            $passwordMaterial = self::resolvePersonelInitialPasswordMaterial($personelId, $ad, $soyad);
            if ($passwordMaterial === null) {
                JsonResponse::badRequest(
                    'Personel hesabi icin gecerli ad ve soyad zorunludur.',
                    self::ERR_NAME_REQUIRED,
                    'ad'
                );
            }
            $passwordHash = PasswordHasher::hash($passwordMaterial);
            $passwordMaterial = null;

            $insertCols = 'username, password_hash, ad_soyad, rol, durum';
            $insertVals = ':username, :password_hash, :ad_soyad, :rol, :durum';
            $params = [
                'username' => $username,
                'password_hash' => $passwordHash,
                'ad_soyad' => $adSoyad,
                'rol' => 'PERSONEL',
                'durum' => 'AKTIF',
            ];

            // Canonical first-login state: aktivasyon linki yok, zorunlu ilk giris sifre degisimi var.
            $insertCols .= ', must_change_password';
            $insertVals .= ', 1';
            $insertCols .= ', activation_required';
            $insertVals .= ', 0';
            if (UsersSchema::hasVarsayilanSubeId($pdo)) {
                $subeId = isset($personel['sube_id']) && $personel['sube_id'] !== null && $personel['sube_id'] !== ''
                    ? (int) $personel['sube_id']
                    : null;
                $insertCols .= ', varsayilan_sube_id';
                $insertVals .= ', :varsayilan_sube_id';
                $params['varsayilan_sube_id'] = $subeId !== null && $subeId > 0 ? $subeId : null;
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO users ($insertCols) VALUES ($insertVals)");
                $stmt->execute($params);
            } catch (PDOException $e) {
                if (self::isUniqueViolation($e)) {
                    // Collision halinde bu transaction'da yapilmis olabilecek onceki
                    // mutation'lar (ornegin master-data name correction) da geri alinir.
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    JsonResponse::error(
                        409,
                        self::ERR_USERNAME_COLLISION,
                        'Bu kullanici adi zaten kullaniliyor. Farkli bir kullanici adi belirleyin.',
                        'username',
                        ['suggested_username' => $suggested]
                    );
                }
                throw $e;
            }

            $userId = (int) $pdo->lastInsertId();
            // Audit izi: plaintext sifre / hash / davet token'i asla yazilmaz.
            self::writeAudit($pdo, self::EVENT_ACCOUNT_CREATED, $userId, $personelId, $actorId, null, [
                'username' => $username,
                'suggested_username' => $suggested,
                'username_overridden' => $usernameOverridden,
                'rol' => 'PERSONEL',
                'credential_model' => self::CREDENTIAL_MODEL_FIRST_LOGIN,
                'activation_required' => 0,
                'must_change_password' => 1,
                'name_correction' => $nameCorrection['correction'],
            ]);

            $bindAction = UserPersonelBindingService::applyBinding($pdo, $userId, $personelId, $actorId);
            self::writeAudit($pdo, self::EVENT_ACCOUNT_BOUND, $userId, $personelId, $actorId, null, [
                'binding_action' => $bindAction,
            ]);

            $pdo->commit();

            return self::buildFirstLoginResponse($pdo, $userId, $username);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject generic create of PERSONEL + personel_id (secure onboarding owns that path).
     *
     * @param array<string, mixed> $body
     */
    public static function rejectGenericPersonelBoundCreate(array $body)
    {
        $rol = strtoupper(trim((string) ($body['rol'] ?? '')));
        if ($rol !== 'PERSONEL') {
            return;
        }
        if (!array_key_exists('personel_id', $body)) {
            // PERSONEL without personel_id still fails existing role org rules.
            return;
        }
        $pid = $body['personel_id'];
        if ($pid === null || $pid === '' || (int) $pid <= 0) {
            return;
        }
        JsonResponse::error(
            409,
            self::ERR_USE_SECURE,
            'Personel baglantili hesap olusturma guvenli onboarding uzerinden yapilmalidir.',
            'personel_id'
        );
    }

    /**
     * İlk ad (küçük Latin) + soyadın ilk harfi (büyük Latin).
     * Sicil numarası katılmaz. Otomatik sayı eklenmez.
     */
    public static function buildPersonelUsernameFromNames($adRaw, $soyadRaw)
    {
        $ad = trim((string) $adRaw);
        $soyad = trim((string) $soyadRaw);
        if ($ad === '' || $soyad === '') {
            JsonResponse::badRequest(
                'Personel hesabi icin ad ve soyad zorunludur.',
                self::ERR_NAME_REQUIRED,
                'ad'
            );
        }

        $adParts = preg_split('/\s+/u', $ad);
        $firstAd = is_array($adParts) && isset($adParts[0]) ? trim((string) $adParts[0]) : '';
        $namePart = self::foldToLowerAsciiToken($firstAd);
        $initial = self::foldSurnameInitial($soyad);
        if ($namePart === '' || $initial === '') {
            JsonResponse::badRequest(
                'Personel hesabi icin gecerli ad ve soyad zorunludur.',
                self::ERR_NAME_REQUIRED,
                'ad'
            );
        }

        return $namePart . $initial;
    }

    /**
     * Baslangic (template) sifresi kurali: soyadin ASCII TitleCase hali + "123".
     *   KOSE -> Kose123, CELIK -> Celik123, SENAY -> Senay123
     *
     * Deterministik ve yalniz server-side'dir. Plaintext hicbir yerde saklanmaz,
     * loglanmaz, response'a konmaz; users.password_hash'e yalniz hash'i yazilir.
     */
    public static function buildPersonelInitialPasswordFromNames($adRaw, $soyadRaw)
    {
        // Username kurali ile ayni ad/soyad onkosullari gecerlidir.
        self::buildPersonelUsernameFromNames($adRaw, $soyadRaw);

        $surnameToken = self::foldToLowerAsciiToken($soyadRaw);
        if ($surnameToken === '') {
            JsonResponse::badRequest(
                'Personel hesabi icin gecerli ad ve soyad zorunludur.',
                self::ERR_NAME_REQUIRED,
                'soyad'
            );
        }

        return ucfirst($surnameToken) . '123';
    }

    /**
     * Personel icin explicit business name correction'i (varsa) doner; yoksa null.
     *
     * @return array{from: array{ad: string|null, soyad: string|null}, to: array{ad: string, soyad: string}}|null
     */
    public static function personelNameCorrectionFor($personelId)
    {
        $id = (int) $personelId;
        $correction = self::PERSONEL_NAME_CORRECTIONS[$id] ?? null;

        return is_array($correction) ? $correction : null;
    }

    /**
     * Personel icin canonical username. Oncelik sirasi:
     *   1) explicit business override (PERSONEL_CREDENTIAL_OVERRIDES)
     *   2) business name correction uygulanmis ad/soyad uzerinden canonical kural
     *   3) kayitli ad/soyad uzerinden canonical kural
     *
     * Cozulemeyen kayitlarda JsonResponse uretmeden null doner (dry-run fail-closed kalir).
     *
     * @return string|null
     */
    public static function resolvePersonelCanonicalUsername($personelId, $ad, $soyad)
    {
        $id = (int) $personelId;
        $override = self::PERSONEL_CREDENTIAL_OVERRIDES[$id] ?? null;
        if (is_array($override) && isset($override['username'])) {
            return (string) $override['username'];
        }

        $correction = self::personelNameCorrectionFor($id);
        if ($correction !== null) {
            $ad = $correction['to']['ad'];
            $soyad = $correction['to']['soyad'];
        }

        return self::canonicalUsernameOrNull($ad, $soyad);
    }

    /**
     * Personel icin template sifre materyali (yalniz bellekte; hash'lenir, saklanmaz/loglanmaz).
     * Override'da `initial_password` varsa o kullanilir; aksi halde canonical soyad kurali
     * (business name correction uygulanmis soyad uzerinden) gecerlidir.
     * Cozulemeyen kayitlarda null doner.
     *
     * @return string|null
     */
    public static function resolvePersonelInitialPasswordMaterial($personelId, $ad, $soyad)
    {
        $id = (int) $personelId;
        $override = self::PERSONEL_CREDENTIAL_OVERRIDES[$id] ?? null;
        if (is_array($override) && isset($override['initial_password'])) {
            return (string) $override['initial_password'];
        }

        if (self::resolvePersonelCanonicalUsername($id, $ad, $soyad) === null) {
            return null;
        }

        $correction = self::personelNameCorrectionFor($id);
        $effectiveSoyad = $correction !== null ? $correction['to']['soyad'] : $soyad;
        $token = self::foldToLowerAsciiToken($effectiveSoyad);
        if ($token === '') {
            return null;
        }

        return ucfirst($token) . '123';
    }

    /**
     * PERSONEL canonical first-login credential gecisi (canonical sahip).
     *
     * Cohort (yalniz bu satirlar):
     *   u.rol = 'PERSONEL'
     *   u.durum = 'AKTIF'
     *   u.username PROTECTED_USERNAMES disinda (rezerve hesaplar korunur)
     *   bagli personel var ve bagli personel aktif_durum = 'AKTIF'
     *
     * Mutation (apply = true):
     *   username             = business override varsa o; aksi halde canonical ad/soyad kurali
     *                          (PERSONEL_CREDENTIAL_OVERRIDES > PERSONEL_NAME_CORRECTIONS > kayit)
     *   activation_required  = 0
     *   must_change_password = 1
     *   password_hash        = PasswordHasher::hash(template sifre materyali)
     *   personeller.ad/soyad = PERSONEL_NAME_CORRECTIONS (yalniz ad/soyad; exact preimage guard'li)
     *
     * Degismeyen alanlar: rol, durum, personel_id, activated_at_utc, sube/bolum/birim/
     * sirket/sgk atamalari. PASIF/anomaly hesaplar cohort disindadir; login fail-closed
     * davranislari zayiflatilmaz.
     *
     * Fail-closed: canonical username cakismasi (cohort ici veya cohort disi bir kullanici)
     * ya da planlanan name correction'in exact preimage uyusmazligi varsa hicbir satir
     * mutate edilmez; sonuc blocked = true + blocker/collisions ile doner.
     * Plaintext sifre ve hash loglanmaz; response'a konmaz.
     *
     * @param int|null $actorUserId Audit izi icin aktor; pinned fingerprint olmadan apply zorunludur.
     * @param bool $apply false ise dry-run: hicbir yazma yapilmaz.
     * @param string|null $expectedPlanFingerprint Apply'in pinledigi plan fingerprint'i. Verilirse
     *        plan bu degerle exact eslesmeli; aksi halde hicbir satir yazilmaz.
     *
     * Replay siniri: apply yalnizca TEK SEFERDIR. Cohort seviyesinde
     * EVENT_FIRST_LOGIN_ROLLOUT_APPLIED kaydi ya da satir seviyesinde daha once uygulanmis bir
     * hedef varsa apply hicbir satir mutate etmeden blocked doner. Bu yuzden ilk giristen sonra
     * sifresini degistirmis bir kullanicinin template sifresi bir rerun ile geri yuklenemez.
     *
     * @return array<string, mixed>
     */
    public static function migrateCanonicalFirstLoginCredentials(
        PDO $pdo,
        $actorUserId = null,
        $apply = false,
        ?string $expectedPlanFingerprint = null
    ) {
        if (!UsersSchema::hasPersonelId($pdo)
            || !UsersSchema::hasActivationRequired($pdo)
            || !UsersSchema::hasMustChangePassword($pdo)
            || !self::hasOnboardingAuditTable($pdo)
        ) {
            JsonResponse::error(
                409,
                self::ERR_SCHEMA,
                'Personel first-login credential semasi hazir degil.'
            );
        }

        $protected = [];
        foreach (self::PROTECTED_USERNAMES as $reserved) {
            $protected[strtolower((string) $reserved)] = true;
        }

        $excluded = [
            'protected_username' => [],
            'user_not_active' => [],
            'binding_missing' => [],
            'bound_personel_missing' => [],
            'bound_personel_not_active' => [],
            'name_unresolved' => [],
        ];
        $candidates = [];
        $credentialSource = [];

        foreach (self::loadPersonelCredentialRows($pdo) as $row) {
            $userId = (int) $row['user_id'];
            $username = (string) $row['username'];
            $entry = [
                'user_id' => $userId,
                'username' => $username,
                'durum' => (string) $row['user_durum'],
                'personel_id' => $row['personel_id'] !== null ? (int) $row['personel_id'] : null,
            ];

            if (isset($protected[strtolower($username)])) {
                $excluded['protected_username'][] = $entry;
                continue;
            }
            if ((string) $row['user_durum'] !== 'AKTIF') {
                // PASIF/kapatilmis hesap: login zaten durum kapisinda fail-closed; dokunulmaz.
                $excluded['user_not_active'][] = $entry;
                continue;
            }
            $personelId = $row['personel_id'] !== null ? (int) $row['personel_id'] : 0;
            if ($personelId <= 0) {
                $excluded['binding_missing'][] = $entry;
                continue;
            }
            if ($row['personel_row_id'] === null) {
                $excluded['bound_personel_missing'][] = $entry;
                continue;
            }
            if (strtoupper(trim((string) $row['personel_aktif_durum'])) !== 'AKTIF') {
                $excluded['bound_personel_not_active'][] = $entry;
                continue;
            }
            $correction = self::personelNameCorrectionFor($personelId);
            $override = self::PERSONEL_CREDENTIAL_OVERRIDES[$personelId] ?? null;
            $effectiveAd = $correction !== null ? $correction['to']['ad'] : $row['personel_ad'];
            $effectiveSoyad = $correction !== null ? $correction['to']['soyad'] : $row['personel_soyad'];

            $canonical = self::resolvePersonelCanonicalUsername($personelId, $effectiveAd, $effectiveSoyad);
            if ($canonical === null) {
                $excluded['name_unresolved'][] = $entry;
                continue;
            }
            $material = self::resolvePersonelInitialPasswordMaterial($personelId, $effectiveAd, $effectiveSoyad);
            if ($material === null) {
                // Username cozulup sifre materyali cozulemezse mutation yapilmaz (fail-closed).
                $excluded['name_unresolved'][] = $entry;
                continue;
            }

            $entry['personel_id'] = $personelId;
            $entry['canonical_username'] = $canonical;
            $entry['business_override'] = is_array($override) && isset($override['username']);
            $entry['name_correction'] = $correction === null ? null : [
                'from' => [
                    'ad' => $correction['from']['ad'],
                    'soyad' => $correction['from']['soyad'],
                ],
                'to' => [
                    'ad' => $correction['to']['ad'],
                    'soyad' => $correction['to']['soyad'],
                ],
                'preimage_match' => self::nullableStringEquals($row['personel_ad'], $correction['from']['ad'])
                    && self::nullableStringEquals($row['personel_soyad'], $correction['from']['soyad']),
            ];
            $candidates[] = $entry;
            $credentialSource[$userId] = [
                'ad' => $effectiveAd,
                'soyad' => $effectiveSoyad,
                'name_correction' => $correction,
                'before_activation_required' => (int) ($row['activation_required'] ?? 0),
                'before_must_change_password' => (int) ($row['must_change_password'] ?? 0),
            ];
        }

        $collisions = self::detectCanonicalUsernameCollisions($pdo, $candidates);
        $plan = [];
        foreach ($candidates as $candidate) {
            $plan[] = [
                'user_id' => $candidate['user_id'],
                'personel_id' => $candidate['personel_id'],
                'old_username' => $candidate['username'],
                'new_username' => $candidate['canonical_username'],
                'username_changed' => $candidate['username'] !== $candidate['canonical_username'],
                'business_override' => $candidate['business_override'],
                'name_correction' => $candidate['name_correction'],
            ];
        }

        // Deterministic, SECRET-FREE plan fingerprint (plaintext sifre / hash asla girmez).
        // Apply bu exact degere pinlenir; cohort kayarsa apply hicbir satir yazmaz.
        $planFingerprint = self::firstLoginPlanFingerprint($plan, $excluded);

        if (count($collisions) > 0) {
            return [
                'apply' => false,
                'blocked' => true,
                'blocker' => self::ERR_CANONICAL_USERNAME_COLLISION,
                'collisions' => $collisions,
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
            ];
        }

        $correctionMismatches = self::nameCorrectionPreimageMismatches($plan);
        if (count($correctionMismatches) > 0) {
            return [
                'apply' => false,
                'blocked' => true,
                'blocker' => self::ERR_NAME_CORRECTION_PREIMAGE,
                'collisions' => [],
                'name_corrections' => $correctionMismatches,
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
            ];
        }

        // Kapsam siniri: rollout disi bir personel plana girerse hicbir satir yazilmaz.
        $rolloutScopeViolations = self::rolloutScopeViolations($plan);
        if (count($rolloutScopeViolations) > 0) {
            return [
                'apply' => false,
                'blocked' => true,
                'blocker' => self::ERR_ROLLOUT_SCOPE_VIOLATION,
                'collisions' => [],
                'rollout_scope_violations' => $rolloutScopeViolations,
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
            ];
        }

        if (!$apply) {
            return [
                'apply' => false,
                'blocked' => false,
                'blocker' => null,
                'collisions' => [],
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
            ];
        }

        // SAME-COHORT GATE: cagiranin pinledigi plan ile yeniden uretilen plan ayni olmali.
        if ($expectedPlanFingerprint !== null
            && strtolower(trim($expectedPlanFingerprint)) !== $planFingerprint
        ) {
            return [
                'apply' => false,
                'blocked' => true,
                'blocker' => self::ERR_PLAN_FINGERPRINT_MISMATCH,
                'collisions' => [],
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
                'expected_plan_fingerprint' => strtolower(trim((string) $expectedPlanFingerprint)),
            ];
        }

        // ONE-SHOT / REPLAY GATE (cohort seviyesi): rollout bir kez uygulanir.
        if (self::firstLoginRolloutApplied($pdo)) {
            return [
                'apply' => false,
                'blocked' => true,
                'blocker' => self::ERR_ROLLOUT_ALREADY_APPLIED,
                'collisions' => [],
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
            ];
        }

        // ONE-SHOT / REPLAY GATE (satir seviyesi): daha once uygulanmis hedef tekrar yazilmaz.
        $appliedUserIds = self::firstLoginCredentialAppliedUserIds($pdo);
        $replayedUserIds = [];
        foreach ($plan as $item) {
            $userId = (int) $item['user_id'];
            if (isset($appliedUserIds[$userId])) {
                $replayedUserIds[] = $userId;
            }
        }
        if (count($replayedUserIds) > 0) {
            return [
                'apply' => false,
                'blocked' => true,
                'blocker' => self::ERR_ROLLOUT_ALREADY_APPLIED,
                'collisions' => [],
                'target_count' => count($plan),
                'plan' => $plan,
                'excluded' => $excluded,
                'applied_count' => 0,
                'audit_event' => null,
                'plan_fingerprint' => $planFingerprint,
                'replayed_user_ids' => $replayedUserIds,
            ];
        }

        $actor = $actorUserId === null ? 0 : (int) $actorUserId;
        // Pinlenmis bir kontrol duzlemi apply'inda insan aktoru yoktur; audit izi NULL aktorle
        // ve request/fingerprint kanitlariyla tutulur. Pinsiz apply hala aktor ister.
        if ($actor <= 0 && $expectedPlanFingerprint === null) {
            JsonResponse::badRequest('Actor user id zorunludur.', 'VALIDATION_ERROR', 'actor_user_id');
        }
        $actorAuditId = $actor > 0 ? $actor : null;

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                "UPDATE users
                    SET username = :new_username,
                        activation_required = 0,
                        must_change_password = 1,
                        password_hash = :password_hash
                  WHERE id = :id
                    AND rol = 'PERSONEL'
                    AND username = :old_username"
            );
            $audit = $pdo->prepare(
                'INSERT INTO personel_account_onboarding_audit
                    (event_type, user_id, personel_id, actor_user_id, invitation_id, detail_json, created_at_utc)
                 VALUES
                    (:event_type, :user_id, :personel_id, :actor_user_id, NULL, :detail_json, :created)'
            );

            $applied = [];
            $nameCorrectionTargets = self::plannedNameCorrections($plan);
            if (count($nameCorrectionTargets) > 0) {
                // Yalniz ad/soyad yazilir; exact `from` preimage kosulu saglanmazsa rollback.
                $correctionUpdate = $pdo->prepare(
                    "UPDATE personeller
                        SET ad = :new_ad,
                            soyad = :new_soyad
                      WHERE id = :personel_id
                        AND ad <=> :old_ad
                        AND soyad <=> :old_soyad"
                );
                foreach ($nameCorrectionTargets as $target) {
                    $correctionUpdate->execute([
                        'new_ad' => $target['to']['ad'],
                        'new_soyad' => $target['to']['soyad'],
                        'personel_id' => $target['personel_id'],
                        'old_ad' => $target['from']['ad'],
                        'old_soyad' => $target['from']['soyad'],
                    ]);
                    if ($correctionUpdate->rowCount() !== 1) {
                        throw new \RuntimeException(self::ERR_NAME_CORRECTION_PREIMAGE);
                    }
                }
            }

            foreach ($plan as $item) {
                $source = $credentialSource[$item['user_id']];
                $material = self::resolvePersonelInitialPasswordMaterial(
                    $item['personel_id'],
                    $source['ad'],
                    $source['soyad']
                );
                if ($material === null) {
                    throw new \RuntimeException('FIRST_LOGIN_PASSWORD_MATERIAL_UNRESOLVED');
                }
                $update->execute([
                    'new_username' => $item['new_username'],
                    'password_hash' => PasswordHasher::hash($material),
                    'id' => $item['user_id'],
                    'old_username' => $item['old_username'],
                ]);
                unset($material);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('FIRST_LOGIN_CREDENTIAL_ROW_MISMATCH');
                }
                // Audit izi: secret (plaintext sifre / hash) asla yazilmaz.
                $audit->execute([
                    'event_type' => self::EVENT_FIRST_LOGIN_CREDENTIALS_APPLIED,
                    'user_id' => $item['user_id'],
                    'personel_id' => $item['personel_id'],
                    'actor_user_id' => $actorAuditId,
                    'detail_json' => json_encode([
                        'source' => 'canonical_first_login_credentials',
                        'business_override' => $item['business_override'],
                        'before' => [
                            'username' => $item['old_username'],
                            'activation_required' => $source['before_activation_required'],
                            'must_change_password' => $source['before_must_change_password'],
                        ],
                        'after' => [
                            'username' => $item['new_username'],
                            'activation_required' => 0,
                            'must_change_password' => 1,
                        ],
                        'name_correction' => is_array($source['name_correction']) ? [
                            'before' => [
                                'ad' => $source['name_correction']['from']['ad'],
                                'soyad' => $source['name_correction']['from']['soyad'],
                            ],
                            'after' => [
                                'ad' => $source['name_correction']['to']['ad'],
                                'soyad' => $source['name_correction']['to']['soyad'],
                            ],
                        ] : null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created' => self::utcNow(),
                ]);
                $applied[] = $item;
            }

            // One-shot rollout ledger: bu exact cohort'un uygulandigi, mutation'larla AYNI
            // transaction icinde kaydedilir. Sonraki bir apply bu kaydi gorup fail-closed durur;
            // boylece rerun, sifresini degistirmis bir kullanicinin template sifresini geri yuklemez.
            // Secret tasimaz: yalniz fingerprint + sayilar.
            self::writeAudit($pdo, self::EVENT_FIRST_LOGIN_ROLLOUT_APPLIED, null, null, $actorAuditId, null, [
                'source' => 'canonical_first_login_credentials',
                'plan_fingerprint' => $planFingerprint,
                'target_count' => count($plan),
                'applied_count' => count($applied),
                'name_correction_count' => count($nameCorrectionTargets),
                'actor_source' => $actorAuditId === null ? 'control_plane' : 'actor_user_id',
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \RuntimeException && $e->getMessage() === self::ERR_NAME_CORRECTION_PREIMAGE) {
                JsonResponse::error(
                    409,
                    self::ERR_NAME_CORRECTION_PREIMAGE,
                    'Personel ad/soyad preimage apply aninda uyusmadi; hicbir satir yazilmadi.'
                );
            }
            JsonResponse::error(
                500,
                'FIRST_LOGIN_CREDENTIALS_APPLY_FAILED',
                'Canonical first-login credential gecisi uygulanamadi.'
            );
        }

        return [
            'apply' => true,
            'blocked' => false,
            'blocker' => null,
            'collisions' => [],
            'target_count' => count($plan),
            'plan' => $plan,
            'excluded' => $excluded,
            'applied_count' => count($applied),
            'audit_event' => self::EVENT_FIRST_LOGIN_CREDENTIALS_APPLIED,
            'rollout_ledger_event' => self::EVENT_FIRST_LOGIN_ROLLOUT_APPLIED,
            'plan_fingerprint' => $planFingerprint,
            'name_correction_count' => count($nameCorrectionTargets),
        ];
    }

    /**
     * onboardAndIssue icin business name correction (varsa) — hesap create ile AYNI transaction.
     *
     * `PERSONEL_NAME_CORRECTIONS` explicit business map'i TEK owner'dir; generic son-kelime
     * parser veya ayri correction tablosu yoktur. Map'te kaydi olmayan personel icin hicbir
     * yazma yapilmaz ve kayitli ad/soyad aynen dondurulur (mevcut davranis korunur).
     *
     * Correction varsa kilitli satirin ad/soyad degeri `from` ile exact (NULL-safe) eslesmeli;
     * eslesmezse hicbir mutation yapilmadan fail-closed doner. Eslesirse yalniz ad/soyad
     * guncellenir ve rowCount tam 1 olmalidir; aksi halde tüm transaction rollback edilir.
     *
     * @param array<string, mixed> $personel lockPersonelRow cikisi (ad/soyad preimage kaynagi)
     * @return array{ad: string, soyad: string, correction: array<string, mixed>|null}
     */
    private static function applyOnboardingNameCorrection(PDO $pdo, array $personel)
    {
        $personelId = (int) ($personel['id'] ?? 0);
        $currentAd = $personel['ad'] ?? null;
        $currentSoyad = $personel['soyad'] ?? null;
        $correction = self::personelNameCorrectionFor($personelId);
        if ($correction === null) {
            return [
                'ad' => (string) $currentAd,
                'soyad' => (string) $currentSoyad,
                'correction' => null,
            ];
        }

        if (!self::nullableStringEquals($currentAd, $correction['from']['ad'])
            || !self::nullableStringEquals($currentSoyad, $correction['from']['soyad'])
        ) {
            JsonResponse::error(
                409,
                self::ERR_NAME_CORRECTION_PREIMAGE,
                'Personel ad/soyad preimage uyusmadi; hicbir satir yazilmadi.',
                'personel_id'
            );
        }

        // Yalniz ad/soyad yazilir; exact `from` preimage kosulu saglanmazsa rollback olur.
        $update = $pdo->prepare(
            "UPDATE personeller
                SET ad = :new_ad,
                    soyad = :new_soyad
              WHERE id = :personel_id
                AND ad <=> :old_ad
                AND soyad <=> :old_soyad"
        );
        $update->execute([
            'new_ad' => $correction['to']['ad'],
            'new_soyad' => $correction['to']['soyad'],
            'personel_id' => $personelId,
            'old_ad' => $correction['from']['ad'],
            'old_soyad' => $correction['from']['soyad'],
        ]);
        if ($update->rowCount() !== 1) {
            JsonResponse::error(
                409,
                self::ERR_NAME_CORRECTION_PREIMAGE,
                'Personel ad/soyad preimage uyusmadi; hicbir satir yazilmadi.',
                'personel_id'
            );
        }

        return [
            'ad' => (string) $correction['to']['ad'],
            'soyad' => (string) $correction['to']['soyad'],
            'correction' => [
                'before' => [
                    'ad' => $correction['from']['ad'],
                    'soyad' => $correction['from']['soyad'],
                ],
                'after' => [
                    'ad' => $correction['to']['ad'],
                    'soyad' => $correction['to']['soyad'],
                ],
            ],
        ];
    }

    /**
     * Deterministic, SECRET-FREE plan fingerprint.
     *
     * Pinlenen cohort'un tamamini kapsar: plan satirlari (user/personel id, eski-yeni kullanici
     * adi, override/correction ve correction preimage'i) ve excluded bucket'lari. Plaintext sifre
     * veya password_hash ASLA bu girdiye girmez; cikti da yalnizca sha256 hex'tir. Ayni DB
     * durumundan her zaman ayni deger uretilir, bu yuzden apply oncesi dry-run ile apply
     * anindaki plan ayni fingerprint'e sahip olmak zorundadir.
     *
     * @param array<int, array<string, mixed>> $plan
     * @param array<string, array<int, array<string, mixed>>> $excluded
     */
    public static function firstLoginPlanFingerprint(array $plan, array $excluded): string
    {
        $rows = [];
        foreach ($plan as $item) {
            $correction = is_array($item['name_correction'] ?? null) ? $item['name_correction'] : null;
            $rows[] = [
                'user_id' => (int) ($item['user_id'] ?? 0),
                'personel_id' => (int) ($item['personel_id'] ?? 0),
                'old_username' => (string) ($item['old_username'] ?? ''),
                'new_username' => (string) ($item['new_username'] ?? ''),
                'username_changed' => ($item['username_changed'] ?? false) === true,
                'business_override' => ($item['business_override'] ?? false) === true,
                'name_correction' => $correction === null ? null : [
                    'from_ad' => $correction['from']['ad'] ?? null,
                    'from_soyad' => $correction['from']['soyad'] ?? null,
                    'to_ad' => $correction['to']['ad'] ?? null,
                    'to_soyad' => $correction['to']['soyad'] ?? null,
                    'preimage_match' => ($correction['preimage_match'] ?? false) === true,
                ],
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return $a['user_id'] <=> $b['user_id'];
        });

        $excludedRows = [];
        foreach ($excluded as $bucket => $bucketRows) {
            foreach (is_array($bucketRows) ? $bucketRows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $excludedRows[] = [
                    'bucket' => (string) $bucket,
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'username' => (string) ($row['username'] ?? ''),
                ];
            }
        }
        usort($excludedRows, static function (array $a, array $b): int {
            return [$a['bucket'], $a['user_id']] <=> [$b['bucket'], $b['user_id']];
        });

        $canonical = json_encode([
            'schema' => 'PERSONEL_FIRST_LOGIN_PLAN_V1',
            'rows' => $rows,
            'excluded' => $excludedRows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $canonical === false ? '' : $canonical);
    }

    /**
     * Cohort seviyesi one-shot kaniti: bu rollout daha once uygulandi mi?
     */
    public static function firstLoginRolloutApplied(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = :event'
        );
        $stmt->execute(['event' => self::EVENT_FIRST_LOGIN_ROLLOUT_APPLIED]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Satir seviyesi replay kaniti: daha once first-login credential gecisi uygulanmis user id seti.
     *
     * @return array<int, bool>
     */
    private static function firstLoginCredentialAppliedUserIds(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT user_id FROM personel_account_onboarding_audit
              WHERE event_type = :event AND user_id IS NOT NULL'
        );
        $stmt->execute(['event' => self::EVENT_FIRST_LOGIN_CREDENTIALS_APPLIED]);
        $userIds = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $userIds[$userId] = true;
            }
        }

        return $userIds;
    }

    /**
     * Rollout kapsami disindaki personel id'lerini iceren plan satirlari.
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    private static function rolloutScopeViolations(array $plan): array
    {
        $violations = [];
        foreach ($plan as $item) {
            $personelId = (int) ($item['personel_id'] ?? 0);
            if (in_array($personelId, self::ROLLOUT_EXCLUDED_PERSONEL_IDS, true)) {
                $violations[] = [
                    'user_id' => (int) ($item['user_id'] ?? 0),
                    'personel_id' => $personelId,
                ];
            }
        }

        return $violations;
    }

    public static function normalizeOverrideUsername($raw)
    {
        $username = trim((string) $raw);
        if ($username === '' || !preg_match('/^[A-Za-z0-9]{2,64}$/', $username)) {
            JsonResponse::badRequest(
                'Kullanici adi gecersiz. Yalniz harf ve rakam kullanin.',
                self::ERR_USERNAME_INVALID,
                'username'
            );
        }

        return $username;
    }

    public static function foldToLowerAsciiToken($value)
    {
        $folded = self::foldTurkishChars((string) $value);
        $lower = strtolower($folded);

        return preg_replace('/[^a-z0-9]/', '', $lower) ?? '';
    }

    public static function foldSurnameInitial($soyad)
    {
        $soyad = trim((string) $soyad);
        if ($soyad === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            $first = mb_substr($soyad, 0, 1, 'UTF-8');
        } else {
            $first = substr($soyad, 0, 1);
        }
        $map = [
            'ç' => 'C', 'Ç' => 'C',
            'ğ' => 'G', 'Ğ' => 'G',
            'ı' => 'I', 'İ' => 'I', 'I' => 'I', 'i' => 'I',
            'ö' => 'O', 'Ö' => 'O',
            'ş' => 'S', 'Ş' => 'S',
            'ü' => 'U', 'Ü' => 'U',
        ];
        if (isset($map[$first])) {
            return $map[$first];
        }
        $ascii = strtoupper(self::foldTurkishChars($first));
        if (!preg_match('/^[A-Z]$/', $ascii)) {
            return '';
        }

        return $ascii;
    }

    public static function foldTurkishChars($value)
    {
        $map = [
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
        ];

        return strtr((string) $value, $map);
    }

    public static function sendNoStoreHeaders()
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
    }

    /**
     * Plan icindeki name correction hedefleri (personel_id + from/to).
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    private static function plannedNameCorrections(array $plan)
    {
        $targets = [];
        foreach ($plan as $item) {
            $correction = $item['name_correction'] ?? null;
            if (!is_array($correction)) {
                continue;
            }
            $targets[] = [
                'personel_id' => (int) $item['personel_id'],
                'from' => $correction['from'],
                'to' => $correction['to'],
            ];
        }

        return $targets;
    }

    /**
     * Preimage'i uyusmayan name correction'lari doner (apply oncesi blocked gate).
     *
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    private static function nameCorrectionPreimageMismatches(array $plan)
    {
        $mismatches = [];
        foreach ($plan as $item) {
            $correction = $item['name_correction'] ?? null;
            if (!is_array($correction) || ($correction['preimage_match'] ?? false) === true) {
                continue;
            }
            $mismatches[] = [
                'user_id' => (int) $item['user_id'],
                'personel_id' => (int) $item['personel_id'],
                'expected_from' => $correction['from'],
                'planned_to' => $correction['to'],
            ];
        }

        return $mismatches;
    }

    /** NULL-safe string karsilastirmasi (name correction exact preimage kontrolu). */
    private static function nullableStringEquals($actual, $expected)
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * Ad/soyad gecersizse JsonResponse uretmeden null doner (dry-run fail-closed kalir).
     *
     * @return string|null
     */
    private static function canonicalUsernameOrNull($ad, $soyad)
    {
        $adTrim = trim((string) $ad);
        $soyadTrim = trim((string) $soyad);
        if ($adTrim === '' || $soyadTrim === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $adTrim);
        $firstAd = is_array($parts) && isset($parts[0]) ? trim((string) $parts[0]) : '';
        if (self::foldToLowerAsciiToken($firstAd) === '' || self::foldSurnameInitial($soyadTrim) === '') {
            return null;
        }

        return self::buildPersonelUsernameFromNames($adTrim, $soyadTrim);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadPersonelCredentialRows(PDO $pdo)
    {
        $stmt = $pdo->query(
            "SELECT u.id AS user_id, u.username, u.durum AS user_durum, u.personel_id,
                    u.activation_required, u.must_change_password,
                    p.id AS personel_row_id, p.ad AS personel_ad, p.soyad AS personel_soyad,
                    p.aktif_durum AS personel_aktif_durum
               FROM users u
               LEFT JOIN personeller p ON p.id = u.personel_id
              WHERE u.rol = 'PERSONEL'
              ORDER BY u.id ASC"
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Cohort ici ve cohort disi canonical username cakismalarini bulur.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private static function detectCanonicalUsernameCollisions(PDO $pdo, array $candidates)
    {
        if (count($candidates) === 0) {
            return [];
        }

        $candidateUserIds = [];
        $byCanonical = [];
        foreach ($candidates as $candidate) {
            $userId = (int) $candidate['user_id'];
            $candidateUserIds[$userId] = true;
            $key = strtolower((string) $candidate['canonical_username']);
            if (!isset($byCanonical[$key])) {
                $byCanonical[$key] = [];
            }
            $byCanonical[$key][] = $userId;
        }

        $takenOutsideCohort = [];
        $stmt = $pdo->query('SELECT id, username FROM users');
        foreach (($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []) as $other) {
            $otherId = (int) $other['id'];
            if (isset($candidateUserIds[$otherId])) {
                continue;
            }
            $takenOutsideCohort[strtolower((string) $other['username'])] = $otherId;
        }

        $collisions = [];
        foreach ($byCanonical as $key => $userIds) {
            if (count($userIds) > 1) {
                $collisions[] = [
                    'canonical_username' => $key,
                    'scope' => 'cohort',
                    'user_ids' => $userIds,
                ];
            }
            if (isset($takenOutsideCohort[$key])) {
                $collisions[] = [
                    'canonical_username' => $key,
                    'scope' => 'outside_cohort',
                    'user_ids' => [$takenOutsideCohort[$key]],
                ];
            }
        }

        return $collisions;
    }

    private static function hasOnboardingAuditTable(PDO $pdo)
    {
        try {
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'personel_account_onboarding_audit'"
            );
            $count = $stmt ? (int) $stmt->fetchColumn() : 0;

            return $count > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Yeni hesap create + canonical first-login state icin gerekli kolonlar.
     * Davet tablosu bu yolun onkosulu DEGILDIR (create yolu davet uretmez).
     */
    private static function assertSchemaReady(PDO $pdo)
    {
        if (!UsersSchema::hasPersonelId($pdo)
            || !UsersSchema::hasActivationRequired($pdo)
            || !UsersSchema::hasMustChangePassword($pdo)
        ) {
            JsonResponse::error(
                409,
                self::ERR_SCHEMA,
                'Personel hesap semasi hazir degil (personel_id / activation_required / must_change_password gerekli).'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function lockPersonelRow(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, ad, soyad, sicil_no, aktif_durum, sube_id, calisan_kapsami
             FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            JsonResponse::error(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 'personel_id');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $personel
     */
    private static function assertEligibleForOnboarding(PDO $pdo, array $personel)
    {
        $aktif = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktif !== 'AKTIF') {
            JsonResponse::error(
                409,
                self::ERR_PERSONEL_INACTIVE,
                'Pasif personele hesap olusturulamaz.',
                'personel_id'
            );
        }

        if (self::isArchivedTestFixture($pdo, (int) $personel['id'])) {
            JsonResponse::error(
                409,
                self::ERR_PERSONEL_ARCHIVED,
                'Arsivlenmis test fixture personeline hesap olusturulamaz.',
                'personel_id'
            );
        }

        $kapsam = strtoupper(trim((string) ($personel['calisan_kapsami'] ?? 'IC_PERSONEL')));
        if ($kapsam !== 'IC_PERSONEL' && $kapsam !== 'DIS_KAYNAK' && $kapsam !== '') {
            JsonResponse::error(
                409,
                'PERSONEL_TYPE_NOT_ELIGIBLE',
                'Bu personel tipi icin hesap olusturma desteklenmiyor.',
                'calisan_kapsami'
            );
        }

        self::buildPersonelUsernameFromNames($personel['ad'] ?? null, $personel['soyad'] ?? null);
    }

    private static function isArchivedTestFixture(PDO $pdo, $personelId)
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM personel_test_fixture_archive_kayitlari WHERE personel_id = :id LIMIT 1'
            );
            $stmt->execute(['id' => (int) $personelId]);

            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findBoundUserForPersonel(PDO $pdo, $personelId)
    {
        $cols = 'id, username, rol, durum, personel_id';
        $stmt = $pdo->prepare(
            "SELECT $cols FROM users WHERE personel_id = :pid LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function assertUsernameAvailableForNewAccount(PDO $pdo, $username, $personelId, $suggestedUsername)
    {
        $stmt = $pdo->prepare(
            'SELECT id, personel_id FROM users WHERE username = :u LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $boundPid = isset($row['personel_id']) && $row['personel_id'] !== null && $row['personel_id'] !== ''
            ? (int) $row['personel_id']
            : 0;
        if ($boundPid === (int) $personelId) {
            JsonResponse::error(
                409,
                self::ERR_ALREADY_PROVISIONED,
                'Bu personelin hesabi zaten mevcut.',
                'personel_id'
            );
        }
        JsonResponse::error(
            409,
            self::ERR_USERNAME_COLLISION,
            'Bu kullanici adi zaten kullaniliyor. Farkli bir kullanici adi belirleyin.',
            'username',
            ['suggested_username' => $suggestedUsername]
        );
    }

    /**
     * Yeni hesap create yolu (canonical first-login) response'u.
     * Secret (plaintext / hash / davet token'i) icermez ve aktivasyon URL'i donmez.
     *
     * @return array<string, mixed>
     */
    private static function buildFirstLoginResponse(PDO $pdo, $userId, $username)
    {
        $userCols = 'id, username, rol, durum, personel_id, must_change_password';
        $stmt = $pdo->prepare("SELECT $userCols FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int) $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new \RuntimeException('FIRST_LOGIN_ACCOUNT_ROW_MISSING');
        }

        return [
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'rol' => (string) $user['rol'],
                'durum' => (string) $user['durum'],
                'personel_id' => isset($user['personel_id']) ? (int) $user['personel_id'] : null,
                'must_change_password' => ((int) ($user['must_change_password'] ?? 0)) === 1,
            ],
            'credential_model' => self::CREDENTIAL_MODEL_FIRST_LOGIN,
            'message' => 'Personel hesabi olusturuldu. Personel, sirket kuralina gore belirlenen '
                . 'baslangic sifresi ile ilk girisi yapip sifresini degistirmelidir.',
        ];
    }

    /**
     * @param array<string, mixed>|null $detail
     */
    private static function writeAudit(
        PDO $pdo,
        $eventType,
        $userId,
        $personelId,
        $actorUserId,
        $invitationId,
        array $detail = null
    ) {
        // Never include secrets in detail.
        if (is_array($detail)) {
            unset(
                $detail['password'],
                $detail['password_hash'],
                $detail['new_password']
            );
        }
        $stmt = $pdo->prepare(
            'INSERT INTO personel_account_onboarding_audit
                (event_type, user_id, personel_id, actor_user_id, invitation_id, detail_json, created_at_utc)
             VALUES
                (:event_type, :user_id, :personel_id, :actor_user_id, :invitation_id, :detail_json, :created)'
        );
        $stmt->execute([
            'event_type' => (string) $eventType,
            'user_id' => $userId !== null && (int) $userId > 0 ? (int) $userId : null,
            'personel_id' => $personelId !== null && (int) $personelId > 0 ? (int) $personelId : null,
            'actor_user_id' => $actorUserId !== null && (int) $actorUserId > 0 ? (int) $actorUserId : null,
            'invitation_id' => $invitationId !== null && (int) $invitationId > 0 ? (int) $invitationId : null,
            'detail_json' => $detail !== null && count($detail) > 0
                ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created' => self::utcNow(),
        ]);
    }

    private static function utcNow()
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function isUniqueViolation(PDOException $e)
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1062) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return strpos($message, '1062') !== false || strpos($message, 'duplicate') !== false;
    }
}
