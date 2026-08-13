<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\RetentionCategories;

/**
 * RAPOR: delete canonical RAPOR surec only.
 * SGK belge/finans + resmi puantaj etki + disiplin vaka links → DEPENDENT_RETENTION_RECORDS_REMAIN.
 * Does not delete SGK catalogs or unrelated periods.
 */
final class RaporDestructionHandler extends SurecTurDestructionHandler
{
    public function category()
    {
        return RetentionCategories::RAPOR;
    }

    protected function surecTuru()
    {
        return 'RAPOR';
    }

    protected function deleteOperationCode()
    {
        return 'DELETE_RAPOR_SUREC';
    }
}
