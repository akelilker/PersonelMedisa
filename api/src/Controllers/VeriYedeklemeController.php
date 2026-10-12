<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Database\FullDatabaseBackupService;
use Medisa\Api\Database\MigrationBackupService;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Ayarlar > Veri Yedekleme (yalnız GENEL_YONETICI).
 *
 *  GET  /yonetim/veri-yedekleme              → son yedek bilgisi
 *  POST /yonetim/veri-yedekleme              → tam mantıksal yedek al (web kökü dışında, sha256, son 7 saklanır)
 *  GET  /yonetim/veri-yedekleme/indir?dosya= → yedeği oturumlu GY'ye HTTPS üzerinden akıt
 *
 * Geri yükleme bu sürümde YOK (ayrı PR). Dosya yolları ve satır içerikleri yanıta/loga yazılmaz.
 */
final class VeriYedeklemeController
{
    public static function durum(Request $request)
    {
        self::assertGenelYonetici($request);
        try {
            $items = FullDatabaseBackupService::listAppBackups(self::apiDirectory());
        } catch (Throwable $e) {
            JsonResponse::error(503, 'YEDEK_DIZINI_HAZIR_DEGIL', 'Yedek klasörü hazır değil.');
        }
        JsonResponse::success([
            'son_yedek' => $items[0] ?? null,
            'yedekler' => $items,
            'saklanan_adet' => FullDatabaseBackupService::APP_BACKUP_KEEP,
            'geri_yukleme_etkin' => false,
        ]);
    }

    public static function olustur(Request $request)
    {
        $actor = self::assertGenelYonetici($request);
        try {
            $meta = FullDatabaseBackupService::createAppBackup(
                Connection::get(),
                self::apiDirectory(),
                'app-' . bin2hex(random_bytes(6))
            );
        } catch (Throwable $e) {
            $code = $e instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_]{2,80}$/', $e->getMessage()) === 1
                ? $e->getMessage()
                : 'APP_BACKUP_FAILED';
            self::audit('YEDEK_AL_HATA', $actor, null, $code);
            JsonResponse::error(500, $code, 'Yedek alınamadı.');
        }
        self::audit('YEDEK_AL', $actor, (string) $meta['file'], null);
        JsonResponse::success($meta, [], 201);
    }

    public static function indir(Request $request)
    {
        $actor = self::assertGenelYonetici($request);
        $name = (string) $request->getQuery('dosya', '');
        try {
            $path = FullDatabaseBackupService::resolveAppBackupForDownload(self::apiDirectory(), $name);
        } catch (Throwable $e) {
            JsonResponse::notFound('Yedek dosyası bulunamadı.');
        }
        self::audit('YEDEK_INDIR', $actor, $name, null);
        $size = filesize($path);
        if (!headers_sent()) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            if ($size !== false) {
                header('Content-Length: ' . $size);
            }
            http_response_code(200);
        }
        readfile($path);
        exit;
    }

    /** @return array<string, mixed> */
    private static function assertGenelYonetici(Request $request): array
    {
        $actor = AuthMiddleware::authenticate($request, true);
        if (RolePermissions::normalizeRole((string) ($actor['rol'] ?? '')) !== 'GENEL_YONETICI') {
            JsonResponse::forbidden('Veri yedekleme yalnız Genel Yönetici içindir.');
        }

        return $actor;
    }

    private static function apiDirectory(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Dosya tabanlı denetim satırı; şifre/token/yol yazılmaz. */
    private static function audit(string $action, array $actor, ?string $file, ?string $code): void
    {
        try {
            $directory = MigrationBackupService::operationsDirectory(self::apiDirectory());
            $line = json_encode([
                'at' => gmdate('Y-m-d\TH:i:s\Z'),
                'action' => $action,
                'user_id' => (int) ($actor['id'] ?? 0),
                'file' => $file,
                'code' => $code,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($directory . DIRECTORY_SEPARATOR . 'veri-yedekleme-audit.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // denetim yazılamazsa işlem bilgisi yine de loga sızdırılmaz
        }
    }
}
