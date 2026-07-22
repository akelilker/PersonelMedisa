<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Document requirement validation without seeded code×document matrix.
 */
final class SgkBelgeGereksinimValidator
{
    /**
     * @param array{
     *   eksik_gun_kodu?: string,
     *   belge_matrisi?: list<array<string,mixed>>,
     *   sunulan_belge_turleri?: list<string>
     * } $input
     */
    public static function validate(array $input): array
    {
        $kod = strtoupper(trim((string) ($input['eksik_gun_kodu'] ?? '')));
        $matris = array_values($input['belge_matrisi'] ?? []);
        $blockers = [];

        if ($matris === []) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Kod×belge matrisi resmi olarak kanitlanmadi.',
                'Resmi belge matrisi onayli katalog ile gelmeden belge gereksinimi uretilmez.'
            );
        } else {
            $hits = [];
            foreach ($matris as $row) {
                if (strtoupper((string) ($row['eksik_gun_kodu'] ?? '')) === $kod) {
                    $hits[] = $row;
                }
            }
            if ($kod !== '' && $hits === []) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_TAMLIK,
                    'Kod icin belge gereksinimi satiri bulunamadi.',
                    'Resmi matriste kodu tanimlayin.'
                );
            }
        }

        $out = [
            'eksik_gun_kodu' => $kod !== '' ? $kod : null,
            'matris_var_mi' => $matris !== [],
            'seed_var_mi' => false,
            'sunulan_belge_turleri' => array_values($input['sunulan_belge_turleri'] ?? []),
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
            'gecerli_mi' => $blockers === [],
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }
}
