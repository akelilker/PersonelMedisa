<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1 / S106: Catalog approval state-machine validation (no DB write).
 */
final class SgkKatalogOnayService
{
    /**
     * @param array{
     *   current_state?: string,
     *   action?: string,
     *   actor_id?: int,
     *   hazirlayan_id?: int|null,
     *   resmi_kaynaklar_incelendi_mi?: bool,
     *   belirsiz_tarihler_uydurulmadi_mi?: bool,
     *   kisitli_kullanim_kabul_edildi_mi?: bool,
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
        $tamlik = $input['tamlik'] ?? SgkKatalogTamlikService::evaluate([]);
        $blockers = [];

        $tamlikDurumu = (string) ($tamlik['tamlik_durumu'] ?? 'TASLAK');
        $onaylanabilir = !empty($tamlik['onaylanabilir_mi']);
        $approvedTamlik = in_array($tamlikDurumu, ['RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM'], true);

        if (!in_array($state, SgkKatalogContracts::ONAY_STATES, true)) {
            $blockers[] = SgkKatalogContracts::blocker(
                'SGK_KATALOG_ONAY_STATE_GECERSIZ',
                'Gecersiz katalog onay state.',
                'State degerini TASLAK/ONAY_BEKLIYOR/ONAYLANDI/IPTAL olarak gonderin.'
            );
        }

        if (!empty($tamlik['blocker_kodlari']) || !$onaylanabilir) {
            if (in_array($action, ['SUBMIT', 'APPROVE'], true)) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_TAMLIK,
                    'Tamlik blocker varken submit/approve reddedilir.',
                    'Once kaynak tamlik kapisini tamamlayin.'
                );
            }
        }

        if (in_array($action, ['SUBMIT', 'APPROVE'], true) && !$approvedTamlik) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'tamlik_durumu RESMI_KAYNAKLI_KISITLI veya DOGRULANMIS_TAM degilken submit/approve reddedilir.',
                'Resmi birincil kaynak paketi ile sinirli veya tam tamlik saglanmadan onay yapilamaz.'
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
            $hazirlayanId = (int) ($input['hazirlayan_id'] ?? 0);
            $actorId = (int) ($input['actor_id'] ?? 0);
            if ($hazirlayanId > 0 && $actorId > 0 && $hazirlayanId === $actorId) {
                $blockers[] = SgkKatalogContracts::blocker(
                    'SELF_APPROVAL',
                    'Hazirlayan kendi katalog surumunu onaylayamaz (dual-control).',
                    'Farkli bir GENEL_YONETICI onaylayicisi secin.'
                );
            }
            if (empty($input['resmi_kaynaklar_incelendi_mi'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_ATTESTATION,
                    'Resmi kaynaklar incelendi attestation eksik.',
                    'Onay oncesi resmi kaynaklari incelediginizi beyan edin.'
                );
            }
            if (empty($input['belirsiz_tarihler_uydurulmadi_mi'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_ATTESTATION,
                    'Belirsiz tarihler uydurulmadi attestation eksik.',
                    'Belirsiz yururluk tarihlerinin uydurulmadigini beyan edin.'
                );
            }
            if (empty($input['kisitli_kullanim_kabul_edildi_mi'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_ATTESTATION,
                    'Kisitli kullanim kabul edildi attestation eksik.',
                    'Kisitli katalog kullanim kosullarini kabul ettiginizi beyan edin.'
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

        $yazmaAktif = $allowed;

        $muhur = [
            'katalog_hash' => (string) ($input['katalog_hash'] ?? ''),
            'manifest_set_hash' => (string) ($input['manifest_set_hash'] ?? ''),
            'esleme_hash' => (string) ($input['esleme_hash'] ?? ''),
            'onceki_surum_kodu' => $input['onceki_surum_kodu'] ?? null,
            'muhur_zamani' => null,
            'muhur_uygulandi_mi' => false,
        ];

        $out = [
            'current_state' => $state,
            'action' => $action,
            'next_state' => $next,
            'allowed_mi' => $allowed,
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
