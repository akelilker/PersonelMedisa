<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Operational evidence class — never substitutes for mevzuat authority.
 */
final class SgkOperasyonelKanitValidator
{
    /**
     * @param array<string,mixed> $kanit
     * @param string|null $fileBytes When provided, re-hash against claimed SHA256.
     */
    public static function validate(array $kanit, ?string $fileBytes = null): array
    {
        $blockers = [];
        $warnings = [];

        $sha = strtolower((string) ($kanit['sha256'] ?? ''));
        $dosya = (string) ($kanit['dosya_adi'] ?? '');
        $bytes = isset($kanit['byte_boyutu']) ? (int) $kanit['byte_boyutu'] : null;

        if ($dosya === '') {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_OP_KANIT,
                'Operasyonel kanit dosya adi eksik.',
                'Dosya adi standardina uygun kanit yukleyin.'
            );
        }
        if (!SgkKatalogContracts::isSha256($sha)) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_OP_KANIT,
                'Operasyonel kanit SHA256 gecersiz.',
                'SHA256 degerini yeniden hesaplayip gonderin.'
            );
        }

        if ($fileBytes !== null) {
            $actual = hash('sha256', $fileBytes);
            if ($bytes !== null && $bytes !== strlen($fileBytes)) {
                $warnings[] = 'BYTE_BOYUTU_UYUSMUYOR';
            }
            if (SgkKatalogContracts::isSha256($sha) && $actual !== $sha) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_OP_KANIT,
                    'Operasyonel kanit icerik hash uyusmuyor.',
                    'Dosyayi yeniden hashleyin veya dogru dosyayi yukleyin.'
                );
            }
            $sha = $actual;
            $bytes = strlen($fileBytes);
        } elseif (array_key_exists('dosya_erisilebilir_mi', $kanit) && !$kanit['dosya_erisilebilir_mi']) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_OP_KANIT,
                'Operasyonel kanit icerigi dogrulanamadi; dosya erisilemez.',
                'Kanit dosyasina erisim saglayin veya byte icerigini dogrulama istegiyle gonderin.'
            );
        }

        if (!empty($kanit['mevzuat_kaynagi_mi'])) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Operasyonel kanit mevzuat authority olarak kullanilamaz.',
                'Ekran goruntusunu OPERASYONEL_DOGRULAMA_KANITI sinifinda tutun; resmi SGK/mevzuat kaynagi ile destekleyin.'
            );
        }

        $normalized = [
            'kanit_turu' => 'OPERASYONEL_DOGRULAMA_KANITI',
            'donem' => (string) ($kanit['donem'] ?? ''),
            'alinma_zamani' => (string) ($kanit['alinma_zamani'] ?? ''),
            'ekran_cikti_kaynagi' => (string) ($kanit['ekran_cikti_kaynagi'] ?? 'e-Bildirge'),
            'dosya_adi' => $dosya,
            'byte_boyutu' => $bytes,
            'sha256' => $sha,
            'yukleyen' => (string) ($kanit['yukleyen'] ?? ''),
            'dogrulayan' => (string) ($kanit['dogrulayan'] ?? ''),
            'aciklama' => (string) ($kanit['aciklama'] ?? ''),
            'destekledigi_kodlar' => array_values($kanit['destekledigi_kodlar'] ?? []),
            'mevzuat_kaynagi_mi' => false,
            'katalog_tamligi_icin_tek_basina_yeterli_mi' => false,
            'warnings' => $warnings,
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
            'gecerli_mi' => $blockers === [],
        ];
        $normalized['response_hash'] = SgkKatalogContracts::sha256Canonical($normalized);

        return $normalized;
    }
}
