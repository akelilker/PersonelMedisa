<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * Central mobile capability gate for personel self-service.
 * DIS_KAYNAK: shell/buttons visible; business actions guarded.
 */
class PersonelMobileCapabilityService
{
    public const MESSAGE_COMING_SOON =
        'Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır.';

    public const CAP_SHELL = 'shell';
    public const CAP_QR_SCAN = 'qr_scan';
    public const CAP_ATTENDANCE_CORRECT = 'attendance_correct';
    public const CAP_PUANTAJ_WRITE = 'puantaj_write';
    public const CAP_IZIN_WRITE = 'izin_write';

    /**
     * @param array<string, mixed>|null $personelRowOrCtx
     * @return array{
     *   calisan_kapsami: string,
     *   shell: bool,
     *   qr_scan: bool,
     *   attendance_correct: bool,
     *   puantaj_write: bool,
     *   izin_write: bool,
     *   coming_soon_message: string|null
     * }
     */
    public static function resolve(?PDO $pdo, $personelId, array $personelRowOrCtx = null)
    {
        $personelId = (int) $personelId;
        $kapsam = 'IC_PERSONEL';
        if (is_array($personelRowOrCtx) && isset($personelRowOrCtx['calisan_kapsami']) && $personelRowOrCtx['calisan_kapsami'] !== null) {
            $kapsam = strtoupper(trim((string) $personelRowOrCtx['calisan_kapsami']));
        } elseif ($pdo !== null && $personelId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT calisan_kapsami FROM personeller WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $personelId]);
                $raw = $stmt->fetchColumn();
                if ($raw !== false && $raw !== null && trim((string) $raw) !== '') {
                    $kapsam = strtoupper(trim((string) $raw));
                }
            } catch (\Throwable $e) {
                $kapsam = 'IC_PERSONEL';
            }
        }
        if ($kapsam === '') {
            $kapsam = 'IC_PERSONEL';
        }

        $isDis = $kapsam === 'DIS_KAYNAK';

        if ($isDis) {
            // Operasyonel mobil: shell + QR + düzeltme talebi.
            // İzin yazma işveren/hukuki sonuç üretebildiği için fail-closed kapalı.
            return [
                'calisan_kapsami' => 'DIS_KAYNAK',
                'shell' => true,
                'qr_scan' => true,
                'attendance_correct' => true,
                'puantaj_write' => false,
                'izin_write' => false,
                'coming_soon_message' => null,
                'info_only_notice' => 'DIŞ KAYNAK — BİLGİ AMAÇLIDIR / ÜCRET VE SGK TAHAKKUKU OLUŞTURMAZ',
            ];
        }

        return [
            'calisan_kapsami' => 'IC_PERSONEL',
            'shell' => true,
            'qr_scan' => true,
            'attendance_correct' => true,
            'puantaj_write' => false,
            'izin_write' => true,
            'coming_soon_message' => null,
            'info_only_notice' => null,
        ];
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    public static function assertBusinessCapability(array $capabilities, $capability)
    {
        $capability = (string) $capability;
        if (!empty($capabilities[$capability])) {
            return;
        }

        throw new PersonelMobileCapabilityException(
            'MOBILE_CAPABILITY_PENDING_SCOPE',
            self::MESSAGE_COMING_SOON,
            403
        );
    }
}
