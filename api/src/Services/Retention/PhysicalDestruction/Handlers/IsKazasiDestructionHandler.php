<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\RetentionCategories;

/**
 * IS_KAZASI: delete canonical IS_KAZASI surec only.
 * Same dependency gates as RAPOR/IZIN-family (SGK links, resmi etki, disiplin vaka).
 * Attachment/BELGE surecler are separate PERSONEL_BELGE targets — not auto-cascaded.
 */
final class IsKazasiDestructionHandler extends SurecTurDestructionHandler
{
    public function category()
    {
        return RetentionCategories::IS_KAZASI;
    }

    protected function surecTuru()
    {
        return 'IS_KAZASI';
    }

    protected function deleteOperationCode()
    {
        return 'DELETE_IS_KAZASI_SUREC';
    }
}
