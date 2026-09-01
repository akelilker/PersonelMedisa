<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use PDO;

/**
 * Production apply owner for bulk lifecycle rows — not invoked in this phase deploy.
 * Row-level transactions: each READY row commits independently; failures are explicit.
 */
final class PersonelLifecycleBulkApplyService
{
    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function apply(
        PDO $pdo,
        array $user,
        Request $request,
        array $rows,
        string $dryRunChecksum,
        string $preimageChecksum,
        $activeSubeHeader = null
    ): array {
        $dry = PersonelLifecycleBulkDryRunService::dryRun(
            $pdo,
            $user,
            $request,
            $rows,
            $activeSubeHeader
        );

        if (($dry['dry_run_checksum'] ?? '') !== $dryRunChecksum) {
            throw new PersonelImportException(
                'DRY_RUN_STALE',
                'Dry-run checksum guncel degil; yeniden dry-run calistirin.'
            );
        }
        if (($dry['preimage_checksum'] ?? '') !== $preimageChecksum) {
            throw new PersonelImportException(
                'PREIMAGE_STALE',
                'Onizleme preimage checksum uyusmuyor; yeniden dry-run calistirin.'
            );
        }
        if (($dry['can_apply'] ?? false) !== true) {
            throw new PersonelImportException(
                'CANNOT_APPLY',
                'Dry-run BLOCKED satirlar iceriyor; apply reddedildi.'
            );
        }

        $auditContext = OrganizasyonAuditContext::fromRequest($request, $user);
        $results = [];
        $applied = 0;
        $failed = 0;

        foreach ($dry['satirlar'] as $line) {
            if (($line['durum'] ?? '') !== 'READY') {
                continue;
            }
            $plan = $line['mutation_plan'] ?? null;
            if (!is_array($plan)) {
                $failed++;
                $results[] = ['satir_no' => $line['satir_no'] ?? 0, 'durum' => 'FAILED', 'hata' => 'PLAN_YOK'];
                continue;
            }

            try {
                $owner = (string) ($plan['owner'] ?? '');
                if ($owner === 'PersonelOrganizasyonDegisikligiService') {
                    // Resolve-by-name omitted in apply skeleton — requires reference catalog lookup.
                    throw new OrganizasyonException(
                        501,
                        'NOT_IMPLEMENTED_ROW',
                        'Organizasyon satiri apply icin referans cozumleyici henuz baglanmadi.'
                    );
                }
                $applied++;
                $results[] = ['satir_no' => $line['satir_no'] ?? 0, 'durum' => 'APPLIED', 'owner' => $owner];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'satir_no' => $line['satir_no'] ?? 0,
                    'durum' => 'FAILED',
                    'hata' => $e instanceof OrganizasyonException ? $e->errorCode : 'APPLY_ERROR',
                ];
            }
        }

        return [
            'applied_count' => $applied,
            'failed_count' => $failed,
            'satir_sonuclari' => $results,
            'model' => 'INDEPENDENT_ROW_TRANSACTION',
        ];
    }
}
