<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class RaporlarController
{
  private const MAX_LIMIT = 100;

  /** @var array<string, string> */
  private static $allowedTips = [
    'personel-ozet' => 'personel-ozet',
    'devamsizlik' => 'devamsizlik',
    'izin' => 'izin',
    'bildirim' => 'bildirim',
  ];

  public static function show(Request $request, $tip)
  {
    $user = AuthMiddleware::authenticate($request, true);
    $tip = (string) $tip;

    if (!isset(self::$allowedTips[$tip])) {
      JsonResponse::badRequest('Desteklenmeyen rapor tipi.', 'UNSUPPORTED_REPORT');
    }

    $scope = SubeScope::resolveScope($user, $request);

    try {
      $pdo = Connection::get();
    } catch (\Throwable $e) {
      JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
    }

    if ($tip === 'personel-ozet') {
      self::showPersonelOzet($request, $pdo, $scope);
      return;
    }

    if ($tip === 'devamsizlik') {
      self::showDevamsizlik($request, $pdo, $scope);
      return;
    }

    if ($tip === 'izin') {
      self::showIzin($request, $pdo, $scope);
      return;
    }

    self::showLegacyReport($pdo, $tip, $scope);
  }

  private static function showPersonelOzet(Request $request, PDO $pdo, $scope)
  {
    $filters = self::parseReportFilters($request);
    $resolved = self::resolveReportSource($pdo, $scope, $filters);

    if ($resolved['kaynak'] === 'SNAPSHOT') {
      $result = self::fetchPersonelOzetSnapshot($pdo, $resolved, $filters, $scope);
    } else {
      $result = self::fetchPersonelOzetLive($pdo, $filters, $scope, $resolved['donem']);
    }

    self::sendReportResponse($result['items'], $result['total'], $filters, $resolved, $scope);
  }

  private static function showDevamsizlik(Request $request, PDO $pdo, $scope)
  {
    $filters = self::parseReportFilters($request);
    $resolved = self::resolveReportSource($pdo, $scope, $filters);

    if ($resolved['kaynak'] === 'SNAPSHOT') {
      $result = self::fetchDevamsizlikSnapshot($pdo, $resolved, $filters, $scope);
    } else {
      $result = self::fetchDevamsizlikLive($pdo, $filters, $scope, $resolved['donem']);
    }

    self::sendReportResponse($result['items'], $result['total'], $filters, $resolved, $scope);
  }

  private static function showIzin(Request $request, PDO $pdo, $scope)
  {
    $filters = self::parseReportFilters($request);
    $resolved = self::resolveReportSource($pdo, $scope, $filters);

    if ($resolved['kaynak'] === 'SNAPSHOT') {
      $result = self::fetchIzinSnapshot($pdo, $resolved, $filters, $scope);
    } else {
      $result = self::fetchIzinLive($pdo, $filters, $scope, $resolved['donem']);
    }

    self::sendReportResponse($result['items'], $result['total'], $filters, $resolved, $scope);
  }

  /**
   * @param array<int, array<string, mixed>> $items
   * @param array<string, mixed> $filters
   * @param array<string, mixed> $resolved
   */
  private static function sendReportResponse(array $items, $total, array $filters, array $resolved, $scope)
  {
    $totalPages = max(1, (int) ceil($total / $filters['limit']));

    JsonResponse::success(
      ['items' => $items],
      [
        'page' => $filters['page'],
        'limit' => $filters['limit'],
        'total' => $total,
        'total_pages' => $totalPages,
        'has_next_page' => $filters['page'] < $totalPages,
        'has_prev_page' => $filters['page'] > 1,
        'kaynak' => $resolved['kaynak'],
        'muhur_id' => $resolved['muhur_id'],
        'donem' => $resolved['donem'],
        'effective_sube_id' => $scope,
      ]
    );
  }

  /** @return array<string, mixed> */
  private static function parseReportFilters(Request $request)
  {
    $page = max(1, (int) ($request->getQuery('page', 1) ?: 1));
    $limit = max(1, min(self::MAX_LIMIT, (int) ($request->getQuery('limit', 10) ?: 10)));
    $aktiflik = (string) $request->getQuery('aktiflik', 'tum');
    $personelId = (int) ($request->getQuery('personel_id', 0) ?: 0);
    $departmanId = (int) ($request->getQuery('departman_id', 0) ?: 0);
    $muhurId = (int) ($request->getQuery('muhur_id', 0) ?: 0);
    $baslangic = trim((string) $request->getQuery('baslangic_tarihi', ''));
    $bitis = trim((string) $request->getQuery('bitis_tarihi', ''));

    if (!in_array($aktiflik, ['aktif', 'pasif', 'tum'], true)) {
      JsonResponse::badRequest('Gecersiz aktiflik filtresi.', 'VALIDATION_ERROR', 'aktiflik');
    }

    if ($baslangic !== '' && !self::isValidDate($baslangic)) {
      JsonResponse::badRequest('Gecersiz baslangic tarihi.', 'VALIDATION_ERROR', 'baslangic_tarihi');
    }

    if ($bitis !== '' && !self::isValidDate($bitis)) {
      JsonResponse::badRequest('Gecersiz bitis tarihi.', 'VALIDATION_ERROR', 'bitis_tarihi');
    }

    if ($baslangic !== '' && $bitis !== '' && $baslangic > $bitis) {
      JsonResponse::badRequest('Baslangic tarihi bitis tarihinden buyuk olamaz.', 'VALIDATION_ERROR', 'baslangic_tarihi');
    }

    return [
      'page' => $page,
      'limit' => $limit,
      'aktiflik' => $aktiflik,
      'personel_id' => $personelId > 0 ? $personelId : null,
      'departman_id' => $departmanId > 0 ? $departmanId : null,
      'muhur_id' => $muhurId > 0 ? $muhurId : null,
      'baslangic_tarihi' => $baslangic !== '' ? $baslangic : null,
      'bitis_tarihi' => $bitis !== '' ? $bitis : null,
      'donem' => self::deriveDonem($baslangic, $bitis),
    ];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array{kaynak: string, muhur_id: int|null, donem: string|null}
   */
  private static function resolveReportSource(PDO $pdo, $scope, array $filters)
  {
    if ($filters['muhur_id'] !== null) {
      $seal = self::findSealById($pdo, (int) $filters['muhur_id']);
      if (!$seal) {
        JsonResponse::notFound('Aylik muhur kaydi bulunamadi.');
      }

      self::assertSealScope((int) $seal['sube_id'], $scope);

      return [
        'kaynak' => 'SNAPSHOT',
        'muhur_id' => (int) $seal['id'],
        'donem' => (string) $seal['donem'],
        'seal' => $seal,
      ];
    }

    $donem = $filters['donem'];
    if ($donem !== null) {
      $seal = self::findSealForDonem($pdo, $donem, $scope);
      if ($seal) {
        return [
          'kaynak' => 'SNAPSHOT',
          'muhur_id' => (int) $seal['id'],
          'donem' => (string) $seal['donem'],
          'seal' => $seal,
        ];
      }
    }

    return [
      'kaynak' => 'LIVE',
      'muhur_id' => null,
      'donem' => $donem,
      'seal' => null,
    ];
  }

  /**
   * @param array<string, mixed> $resolved
   * @param array<string, mixed> $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  private static function fetchPersonelOzetSnapshot(PDO $pdo, array $resolved, array $filters, $scope)
  {
    $where = ['1=1'];
    $params = [];

    if ($filters['muhur_id'] !== null) {
      $where[] = 'snap.muhur_id = :muhur_id';
      $params['muhur_id'] = (int) $filters['muhur_id'];
    } else {
      $where[] = 'm.donem = :donem';
      $params['donem'] = (string) $resolved['donem'];
      if ($scope !== null) {
        $where[] = 'm.sube_id = :scope_sube_id';
        $params['scope_sube_id'] = $scope;
      }
    }

    self::appendPersonelFilters($where, $params, $filters, 'p');
    self::appendSnapshotDateFilters($where, $params, $filters);

    $whereSql = implode(' AND ', $where);
    $fromSql = '
      FROM puantaj_aylik_muhur_satirlari snap
      INNER JOIN puantaj_aylik_muhurleri m ON m.id = snap.muhur_id
      INNER JOIN personeller p ON p.id = snap.personel_id
      LEFT JOIN subeler s ON s.id = p.sube_id
      LEFT JOIN departmanlar d ON d.id = p.departman_id
      WHERE ' . $whereSql;

    $total = self::countGroupedPersonel($pdo, $fromSql, $params);
    $offset = ($filters['page'] - 1) * $filters['limit'];

    $sql = '
      SELECT
        p.id AS personel_id,
        CONCAT(p.ad, \' \', p.soyad) AS ad_soyad,
        p.sicil_no,
        p.aktif_durum,
        s.ad AS sube,
        d.ad AS bolum,
        COALESCE(SUM(snap.net_calisma_suresi_dakika), 0) AS net_calisma_dakika,
        LEAST(30, COUNT(DISTINCT snap.tarih)) AS sgk_prim_gun,
        COUNT(DISTINCT CASE WHEN COALESCE(snap.net_calisma_suresi_dakika, 0) > 0 THEN snap.tarih END) AS toplam_calisma_gunu
      ' . $fromSql . '
      GROUP BY p.id, p.ad, p.soyad, p.sicil_no, p.aktif_durum, s.ad, d.ad
      ORDER BY p.id ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = self::mapPersonelOzetRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  private static function fetchPersonelOzetLive(PDO $pdo, array $filters, $scope, $donem)
  {
    $where = ['1=1'];
    $params = [];

    if ($scope !== null) {
      $where[] = 'p.sube_id = :scope_sube_id';
      $params['scope_sube_id'] = $scope;
    }

    self::appendPersonelFilters($where, $params, $filters, 'p');

    $gpDateSql = self::buildLiveDateSql($filters, $donem, $params);
    $whereSql = implode(' AND ', $where);

    $fromSql = '
      FROM personeller p
      LEFT JOIN subeler s ON s.id = p.sube_id
      LEFT JOIN departmanlar d ON d.id = p.departman_id
      LEFT JOIN gunluk_puantaj gp ON gp.personel_id = p.id' . $gpDateSql . '
      WHERE ' . $whereSql;

    $total = self::countGroupedPersonel($pdo, $fromSql, $params);
    $offset = ($filters['page'] - 1) * $filters['limit'];

    $sql = '
      SELECT
        p.id AS personel_id,
        CONCAT(p.ad, \' \', p.soyad) AS ad_soyad,
        p.sicil_no,
        p.aktif_durum,
        s.ad AS sube,
        d.ad AS bolum,
        COALESCE(SUM(gp.net_calisma_suresi_dakika), 0) AS net_calisma_dakika,
        LEAST(30, COUNT(DISTINCT gp.tarih)) AS sgk_prim_gun,
        COUNT(DISTINCT CASE WHEN COALESCE(gp.net_calisma_suresi_dakika, 0) > 0 THEN gp.tarih END) AS toplam_calisma_gunu
      ' . $fromSql . '
      GROUP BY p.id, p.ad, p.soyad, p.sicil_no, p.aktif_durum, s.ad, d.ad
      ORDER BY p.id ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = self::mapPersonelOzetRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  /**
   * @param array<string, mixed> $resolved
   * @param array<string, mixed> $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  private static function fetchDevamsizlikSnapshot(PDO $pdo, array $resolved, array $filters, $scope)
  {
    $where = ['1=1'];
    $params = [];

    if ($filters['muhur_id'] !== null) {
      $where[] = 'snap.muhur_id = :muhur_id';
      $params['muhur_id'] = (int) $filters['muhur_id'];
    } else {
      $where[] = 'm.donem = :donem';
      $params['donem'] = (string) $resolved['donem'];
      if ($scope !== null) {
        $where[] = 'm.sube_id = :scope_sube_id';
        $params['scope_sube_id'] = $scope;
      }
    }

    self::appendPersonelFilters($where, $params, $filters, 'p');
    self::appendSnapshotDateFilters($where, $params, $filters);
    self::appendDevamsizlikAbsenceFilter($where, 'snap');

    $whereSql = implode(' AND ', $where);
    $fromSql = '
      FROM puantaj_aylik_muhur_satirlari snap
      INNER JOIN puantaj_aylik_muhurleri m ON m.id = snap.muhur_id
      INNER JOIN personeller p ON p.id = snap.personel_id
      LEFT JOIN subeler s ON s.id = p.sube_id
      LEFT JOIN departmanlar d ON d.id = p.departman_id
      WHERE ' . $whereSql;

    $total = self::countDevamsizlikRows($pdo, $fromSql, $params);
    $offset = ($filters['page'] - 1) * $filters['limit'];

    $sql = '
      SELECT
        p.id AS personel_id,
        CONCAT(p.ad, \' \', p.soyad) AS ad_soyad,
        snap.tarih AS baslangic_tarihi,
        snap.tarih AS bitis_tarihi,
        \'IZINSIZ\' AS alt_tur,
        \'MUHURLENDI\' AS state
      ' . $fromSql . '
      ORDER BY snap.tarih ASC, p.id ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = self::mapDevamsizlikRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  private static function fetchDevamsizlikLive(PDO $pdo, array $filters, $scope, $donem)
  {
    $where = ['1=1'];
    $params = [];

    if ($scope !== null) {
      $where[] = 'p.sube_id = :scope_sube_id';
      $params['scope_sube_id'] = $scope;
    }

    self::appendPersonelFilters($where, $params, $filters, 'p');
    self::appendLiveDateFilters($where, $params, $filters, $donem, 'gp.tarih');
    self::appendDevamsizlikAbsenceFilter($where, 'gp');

    $whereSql = implode(' AND ', $where);
    $fromSql = '
      FROM gunluk_puantaj gp
      INNER JOIN personeller p ON p.id = gp.personel_id
      LEFT JOIN subeler s ON s.id = p.sube_id
      LEFT JOIN departmanlar d ON d.id = p.departman_id
      WHERE ' . $whereSql;

    $total = self::countDevamsizlikRows($pdo, $fromSql, $params);
    $offset = ($filters['page'] - 1) * $filters['limit'];

    $sql = '
      SELECT
        p.id AS personel_id,
        CONCAT(p.ad, \' \', p.soyad) AS ad_soyad,
        gp.tarih AS baslangic_tarihi,
        gp.tarih AS bitis_tarihi,
        \'IZINSIZ\' AS alt_tur,
        COALESCE(gp.state, \'ACIK\') AS state
      ' . $fromSql . '
      ORDER BY gp.tarih ASC, p.id ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = self::mapDevamsizlikRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  /**
   * @param array<string, mixed> $resolved
   * @param array<string, mixed> $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  private static function fetchIzinSnapshot(PDO $pdo, array $resolved, array $filters, $scope)
  {
    $where = ['1=1'];
    $params = [];

    if ($filters['muhur_id'] !== null) {
      $where[] = 'snap.muhur_id = :muhur_id';
      $params['muhur_id'] = (int) $filters['muhur_id'];
    } else {
      $where[] = 'm.donem = :donem';
      $params['donem'] = (string) $resolved['donem'];
      if ($scope !== null) {
        $where[] = 'm.sube_id = :scope_sube_id';
        $params['scope_sube_id'] = $scope;
      }
    }

    self::appendPersonelFilters($where, $params, $filters, 'p');
    self::appendSnapshotDateFilters($where, $params, $filters);
    self::appendIzinFilter($where, 'snap');

    $whereSql = implode(' AND ', $where);
    $fromSql = '
      FROM puantaj_aylik_muhur_satirlari snap
      INNER JOIN puantaj_aylik_muhurleri m ON m.id = snap.muhur_id
      INNER JOIN personeller p ON p.id = snap.personel_id
      LEFT JOIN subeler s ON s.id = p.sube_id
      LEFT JOIN departmanlar d ON d.id = p.departman_id
      WHERE ' . $whereSql;

    $total = self::countDevamsizlikRows($pdo, $fromSql, $params);
    $offset = ($filters['page'] - 1) * $filters['limit'];

    $sql = '
      SELECT
        p.id AS personel_id,
        CONCAT(p.ad, \' \', p.soyad) AS ad_soyad,
        snap.tarih AS baslangic_tarihi,
        snap.tarih AS bitis_tarihi,
        CASE snap.dayanak
          WHEN \'Yillik_Izin\' THEN \'YILLIK_IZIN\'
          WHEN \'Ucretli_Izinli\' THEN \'UCRETLI_IZIN\'
        END AS alt_tur,
        1 AS ucretli_mi,
        \'MUHURLENDI\' AS state
      ' . $fromSql . '
      ORDER BY snap.tarih ASC, p.id ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = self::mapIzinRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  /**
   * @param array<string, mixed> $filters
   * @return array{items: array<int, array<string, mixed>>, total: int}
   */
  private static function fetchIzinLive(PDO $pdo, array $filters, $scope, $donem)
  {
    $where = ['1=1'];
    $params = [];

    if ($scope !== null) {
      $where[] = 'p.sube_id = :scope_sube_id';
      $params['scope_sube_id'] = $scope;
    }

    self::appendPersonelFilters($where, $params, $filters, 'p');
    self::appendLiveDateFilters($where, $params, $filters, $donem, 'gp.tarih');
    self::appendIzinFilter($where, 'gp');

    $whereSql = implode(' AND ', $where);
    $fromSql = '
      FROM gunluk_puantaj gp
      INNER JOIN personeller p ON p.id = gp.personel_id
      LEFT JOIN subeler s ON s.id = p.sube_id
      LEFT JOIN departmanlar d ON d.id = p.departman_id
      WHERE ' . $whereSql;

    $total = self::countDevamsizlikRows($pdo, $fromSql, $params);
    $offset = ($filters['page'] - 1) * $filters['limit'];

    $sql = '
      SELECT
        p.id AS personel_id,
        CONCAT(p.ad, \' \', p.soyad) AS ad_soyad,
        gp.tarih AS baslangic_tarihi,
        gp.tarih AS bitis_tarihi,
        CASE gp.dayanak
          WHEN \'Yillik_Izin\' THEN \'YILLIK_IZIN\'
          WHEN \'Ucretli_Izinli\' THEN \'UCRETLI_IZIN\'
        END AS alt_tur,
        1 AS ucretli_mi,
        COALESCE(gp.state, \'ACIK\') AS state
      ' . $fromSql . '
      ORDER BY gp.tarih ASC, p.id ASC
      LIMIT :limit OFFSET :offset
    ';

    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $items[] = self::mapIzinRow($row);
    }

    return ['items' => $items, 'total' => $total];
  }

  private static function showLegacyReport(PDO $pdo, $tip, $scope)
  {
    $where = ['1=1'];
    $params = [];
    if ($scope !== null) {
      $where[] = 'p.sube_id = :scope_sube_id';
      $params['scope_sube_id'] = $scope;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "
            SELECT p.id AS personel_id, CONCAT(p.ad, ' ', p.soyad) AS ad_soyad, p.sicil_no,
                   s.ad AS sube, d.ad AS bolum
            FROM personeller p
            LEFT JOIN subeler s ON s.id = p.sube_id
            LEFT JOIN departmanlar d ON d.id = p.departman_id
            WHERE $whereSql
            ORDER BY p.id ASC
        ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
      $items[] = self::mapLegacyReportRow($tip, $row);
    }

    JsonResponse::success(
      ['items' => $items],
      [
        'page' => 1,
        'limit' => count($items),
        'total' => count($items),
        'total_pages' => 1,
      ]
    );
  }

  /** @param array<int, string> $where @param array<string, mixed> $params */
  private static function appendPersonelFilters(array &$where, array &$params, array $filters, $personelAlias)
  {
    if ($filters['personel_id'] !== null) {
      $where[] = $personelAlias . '.id = :personel_id';
      $params['personel_id'] = $filters['personel_id'];
    }

    if ($filters['departman_id'] !== null) {
      $where[] = $personelAlias . '.departman_id = :departman_id';
      $params['departman_id'] = $filters['departman_id'];
    }

    if ($filters['aktiflik'] === 'aktif') {
      $where[] = $personelAlias . ".aktif_durum = 'AKTIF'";
    } elseif ($filters['aktiflik'] === 'pasif') {
      $where[] = $personelAlias . ".aktif_durum = 'PASIF'";
    }
  }

  /** @param array<int, string> $where @param array<string, mixed> $params */
  private static function appendSnapshotDateFilters(array &$where, array &$params, array $filters)
  {
    if ($filters['baslangic_tarihi'] !== null) {
      $where[] = 'snap.tarih >= :baslangic_tarihi';
      $params['baslangic_tarihi'] = $filters['baslangic_tarihi'];
    }

    if ($filters['bitis_tarihi'] !== null) {
      $where[] = 'snap.tarih <= :bitis_tarihi';
      $params['bitis_tarihi'] = $filters['bitis_tarihi'];
    }
  }

  /** @param array<string, mixed> $filters @param array<string, mixed> $params */
  private static function buildLiveDateSql(array $filters, $donem, array &$params)
  {
    if ($filters['baslangic_tarihi'] !== null && $filters['bitis_tarihi'] !== null) {
      $params['live_baslangic'] = $filters['baslangic_tarihi'];
      $params['live_bitis'] = $filters['bitis_tarihi'];

      return ' AND gp.tarih BETWEEN :live_baslangic AND :live_bitis';
    }

    if ($donem !== null && preg_match('/^(\d{4})-(\d{2})$/', (string) $donem, $matches)) {
      $firstDay = sprintf('%04d-%02d-01', (int) $matches[1], (int) $matches[2]);
      $lastDay = date('Y-m-t', strtotime($firstDay));
      $params['live_baslangic'] = $firstDay;
      $params['live_bitis'] = $lastDay;

      return ' AND gp.tarih BETWEEN :live_baslangic AND :live_bitis';
    }

    return '';
  }

  /** @param array<int, string> $where */
  private static function appendIzinFilter(array &$where, $alias)
  {
    $where[] = $alias . ".hareket_durumu = 'Gelmedi'";
    $where[] = $alias . ".dayanak IN ('Yillik_Izin', 'Ucretli_Izinli')";
  }

  /** @param array<int, string> $where */
  private static function appendDevamsizlikAbsenceFilter(array &$where, $alias)
  {
    $where[] = $alias . ".hareket_durumu = 'Gelmedi'";
    $where[] = $alias . ".dayanak = 'Yok_Izinsiz'";
  }

  /** @param array<int, string> $where @param array<string, mixed> $params */
  private static function appendLiveDateFilters(array &$where, array &$params, array $filters, $donem, $tarihAlias)
  {
    if ($filters['baslangic_tarihi'] !== null && $filters['bitis_tarihi'] !== null) {
      $where[] = $tarihAlias . ' BETWEEN :live_baslangic AND :live_bitis';
      $params['live_baslangic'] = $filters['baslangic_tarihi'];
      $params['live_bitis'] = $filters['bitis_tarihi'];

      return;
    }

    if ($donem !== null && preg_match('/^(\d{4})-(\d{2})$/', (string) $donem, $matches)) {
      $firstDay = sprintf('%04d-%02d-01', (int) $matches[1], (int) $matches[2]);
      $lastDay = date('Y-m-t', strtotime($firstDay));
      $where[] = $tarihAlias . ' BETWEEN :live_baslangic AND :live_bitis';
      $params['live_baslangic'] = $firstDay;
      $params['live_bitis'] = $lastDay;
    }
  }

  /** @param array<string, mixed> $params */
  private static function countDevamsizlikRows(PDO $pdo, $fromSql, array $params)
  {
    $sql = 'SELECT COUNT(*) AS total ' . $fromSql;
    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['total'] ?? 0);
  }

  /** @param array<string, mixed> $params */
  private static function countGroupedPersonel(PDO $pdo, $fromSql, array $params)
  {
    $sql = 'SELECT COUNT(*) AS total FROM (SELECT p.id ' . $fromSql . ' GROUP BY p.id) grouped';
    $stmt = $pdo->prepare($sql);
    self::bindParams($stmt, $params);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['total'] ?? 0);
  }

  /** @param array<string, mixed> $params */
  private static function bindParams(\PDOStatement $stmt, array $params)
  {
    foreach ($params as $key => $value) {
      $stmt->bindValue(':' . $key, $value);
    }
  }

  /** @return array<string, mixed>|false */
  private static function findSealById(PDO $pdo, $muhurId)
  {
    $stmt = $pdo->prepare('SELECT * FROM puantaj_aylik_muhurleri WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $muhurId]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /** @return array<string, mixed>|false */
  private static function findSealForDonem(PDO $pdo, $donem, $scope)
  {
    if ($scope !== null) {
      $stmt = $pdo->prepare(
        'SELECT * FROM puantaj_aylik_muhurleri
         WHERE donem = :donem AND sube_id = :sube_id
         ORDER BY id DESC
         LIMIT 1'
      );
      $stmt->execute([
        'donem' => $donem,
        'sube_id' => $scope,
      ]);

      return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare(
      'SELECT * FROM puantaj_aylik_muhurleri
       WHERE donem = :donem
       ORDER BY id DESC
       LIMIT 1'
    );
    $stmt->execute(['donem' => $donem]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  private static function assertSealScope($sealSubeId, $scope)
  {
    if ($scope !== null && (int) $sealSubeId !== (int) $scope) {
      JsonResponse::forbidden('Bu kayit aktif sube baglaminda goruntulenemiyor.');
    }
  }

  private static function isValidDate($value)
  {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
      return false;
    }

    $parts = explode('-', (string) $value);

    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
  }

  private static function deriveDonem($baslangic, $bitis)
  {
    if ($baslangic === '' || $bitis === '') {
      return null;
    }

    if (substr($baslangic, 0, 7) !== substr($bitis, 0, 7)) {
      return null;
    }

    return substr($baslangic, 0, 7);
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private static function mapPersonelOzetRow(array $row)
  {
    return [
      'personel_id' => (int) $row['personel_id'],
      'ad_soyad' => (string) $row['ad_soyad'],
      'sicil_no' => $row['sicil_no'],
      'aktif_durum' => (string) $row['aktif_durum'],
      'sube' => $row['sube'],
      'bolum' => $row['bolum'],
      'net_calisma_dakika' => (int) $row['net_calisma_dakika'],
      'sgk_prim_gun' => min(30, (int) $row['sgk_prim_gun']),
      'toplam_calisma_gunu' => (int) $row['toplam_calisma_gunu'],
    ];
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private static function mapIzinRow(array $row)
  {
    return [
      'personel_id' => (int) $row['personel_id'],
      'ad_soyad' => (string) $row['ad_soyad'],
      'baslangic_tarihi' => (string) $row['baslangic_tarihi'],
      'bitis_tarihi' => (string) $row['bitis_tarihi'],
      'alt_tur' => (string) $row['alt_tur'],
      'ucretli_mi' => (bool) ((int) ($row['ucretli_mi'] ?? 0)),
      'state' => (string) $row['state'],
    ];
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private static function mapDevamsizlikRow(array $row)
  {
    return [
      'personel_id' => (int) $row['personel_id'],
      'ad_soyad' => (string) $row['ad_soyad'],
      'baslangic_tarihi' => (string) $row['baslangic_tarihi'],
      'bitis_tarihi' => (string) $row['bitis_tarihi'],
      'alt_tur' => (string) $row['alt_tur'],
      'state' => (string) $row['state'],
    ];
  }

  /** @param array<string, mixed> $row @return array<string, mixed> */
  private static function mapLegacyReportRow($tip, array $row)
  {
    $base = [
      'personel_id' => (int) $row['personel_id'],
      'ad_soyad' => (string) $row['ad_soyad'],
      'sicil_no' => $row['sicil_no'],
      'sube' => $row['sube'],
      'bolum' => $row['bolum'],
    ];

    return $base;
  }
}
