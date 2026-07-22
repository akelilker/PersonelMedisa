<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Catalog approval state-machine validation only (no DB write).
 */
final class SgkKatalogOnayService
{
    /**
     * @param array{
     *   current_state?: string,
     *   action?: string,
     *   actor_id?: int,
     *   hazirlayan_id?: int|null,
     *   mali_musavir_onayladi_mi?: bool,
     *   sirket_onayladi_mi?: bool,
     *   tamlik?: array<string,mixed>|null,
     *   katalog_hash?: string|null,
     *   manifest_set_hash?: string|null,
     *   esleme_hash?: string|null,
     *   onceki_surum_kodu?: string|null
     * } $input
     */
    public static function validateTransition(array $input): array
    {
        $state = strtoupper((string) ($input['current_state'] ?? 'TASLAK'));
        $action = strtoupper((string) ($input['action'] ?? ''));
        $actorId = (int) ($input['actor_id'] ?? 0);
        $hazirlayanId = isset($input['hazirlayan_id']) ? (int) $input['hazirlayan_id'] : null;
        $tamlik = $input['tamlik'] ?? SgkKatalogTamlikService::evaluate([]);
        $blockers = [];

        if (!in_array($state, SgkKatalogContracts::ONAY_STATES, true)) {
            $blockers[] = SgkKatalogContracts::blocker(
                'SGK_KATALOG_ONAY_STATE_GECERSIZ',
                'Gecersiz katalog onay state.',
                'State degerini TASLAK/ONAY_BEKLIYOR/ONAYLANDI/IPTAL olarak gonderin.'
            );
        }

        if (!empty($tamlik['blocker_kodlari']) || empty($tamlik['onaylanabilir_mi'])) {
            if (in_array($action, ['SUBMIT', 'APPROVE'], true)) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_TAMLIK,
                    'Tamlik blocker varken submit/approve reddedilir.',
                    'Once kaynak tamlik kapisini tamamlayin.'
                );
            }
        }

        if (($tamlik['tamlik_durumu'] ?? 'TASLAK') !== 'DOGRULANMIS_TAM' && $action === 'APPROVE') {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'tamlik_durumu DOGRULANMIS_TAM degilken approve reddedilir.',
                'Resmi kaynak tamligi tamamlanmadan onay yapilamaz.'
            );
        }

        $next = $state;
        $allowed = false;

        if ($action === 'SUBMIT') {
            if ($state !== 'TASLAK') {
                $blockers[] = self::stateBlocker('SUBMIT yalniz TASLAK uzerinden.');
            } else {
                $next = 'ONAY_BEKLIYOR';
                $allowed = $blockers === [];
            }
        } elseif ($action === 'APPROVE') {
            if ($state !== 'ONAY_BEKLIYOR') {
                $blockers[] = self::stateBlocker('APPROVE yalniz ONAY_BEKLIYOR uzerinden.');
            }
            if ($hazirlayanId !== null && $actorId > 0 && $actorId === $hazirlayanId) {
                $blockers[] = SgkKatalogContracts::blocker(
                    'SGK_KATALOG_ONAY_HAZIRLAYAN_AYNI',
                    'Hazirlayan kendi kaydini onaylayamaz.',
                    'Farkli yetkili kullanici ile onaylayin.'
                );
            }
            if (empty($input['mali_musavir_onayladi_mi'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    'SGK_KATALOG_MALI_MUSAVIR_ONAYI_EKSIK',
                    'Mali musavir kontrolu olmadan sirket onayi tamamlanamaz.',
                    'Mali musavir onayini tamamlayin.'
                );
            }
            if (empty($input['sirket_onayladi_mi'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    'SGK_KATALOG_SIRKET_ONAYI_EKSIK',
                    'Sirket onayi olmadan teknik yayin yapilamaz.',
                    'Genel yonetici sirket onayini tamamlayin.'
                );
            }
            $next = 'ONAYLANDI';
            $allowed = $blockers === [];
        } elseif ($action === 'REJECT' || $action === 'IPTAL') {
            if (!in_array($state, ['TASLAK', 'ONAY_BEKLIYOR'], true)) {
                $blockers[] = self::stateBlocker('IPTAL yalniz TASLAK/ONAY_BEKLIYOR uzerinden.');
            } else {
                $next = 'IPTAL';
                $allowed = $blockers === [];
            }
        } elseif ($action === 'UPDATE' || $action === 'DELETE') {
            if ($state === 'ONAYLANDI') {
                $blockers[] = SgkKatalogContracts::blocker(
                    'SGK_KATALOG_SURUM_IMMUTABLE',
                    'Onaylanmis surum update/delete edilemez; duzeltme yeni surumle yapilir.',
                    'Yeni katalog surumu olusturun ve onceki_surum baglantisini koruyun.'
                );
            }
            $allowed = $blockers === [];
        } elseif ($action === 'NEW_VERSION') {
            if (empty($input['onceki_surum_kodu'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    'SGK_KATALOG_ONCEKI_SURUM_BAGLANTISI_EKSIK',
                    'Yeni surum icin onceki surum baglantisi zorunludur.',
                    'onceki_surum_kodu alanini gonderin.'
                );
            }
            $next = 'TASLAK';
            $allowed = $blockers === [];
        } else {
            $blockers[] = self::stateBlocker('Bilinmeyen action: ' . $action);
        }

        // Even if transition mathematically allowed, S85-C1 keeps write inactive.
        $yazmaAktif = false;
        if ($allowed) {
            $allowed = false;
            $blockers[] = SgkKatalogContracts::blocker(
                'SGK_KATALOG_YAZMA_KAPALI',
                'S85-C1 asamasinda onay transition write endpointi aktif degil.',
                'Kaynak tamligi tamamlandiktan sonra ayri yetki ile yazma acilabilir.'
            );
        }

        $muhur = [
            'katalog_hash' => (string) ($input['katalog_hash'] ?? ''),
            'manifest_set_hash' => (string) ($input['manifest_set_hash'] ?? ''),
            'esleme_hash' => (string) ($input['esleme_hash'] ?? ''),
            'onceki_surum_kodu' => $input['onceki_surum_kodu'] ?? null,
            // Validation-only: wall-clock stamp would break deterministic response_hash.
            'muhur_zamani' => null,
            'muhur_uygulandi_mi' => false,
        ];

        $out = [
            'current_state' => $state,
            'action' => $action,
            'next_state' => $next,
            'allowed_mi' => false,
            'yazma_aktif_mi' => $yazmaAktif,
            'muhur' => $muhur,
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }

    private static function stateBlocker(string $message): array
    {
        return SgkKatalogContracts::blocker(
            'SGK_KATALOG_ONAY_GECIS_REDDI',
            $message,
            'Onay state makinesi kurallarina uygun action gonderin.'
        );
    }
}
