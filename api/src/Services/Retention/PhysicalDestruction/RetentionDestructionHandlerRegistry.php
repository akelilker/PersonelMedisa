<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\BordroDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\IseGirisCikisDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\IzinDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\OlayDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\OnayAuditDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PersonelBelgeDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PersonelOzlukDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PolicyRequiredDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PuantajDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\SavunmaDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\SgkEksikGunDestructionHandler;
use Medisa\Api\Services\Retention\RetentionCategories;
use RuntimeException;

/**
 * CATEGORY → typed handler registry (server-owned; never client-selected).
 */
final class RetentionDestructionHandlerRegistry
{
    /** @var array<string, DestructionHandlerInterface>|null */
    private static $handlers = null;

    /**
     * @return DestructionHandlerInterface
     */
    public static function forCategory($category)
    {
        $map = self::all();
        $category = (string) $category;
        if (!isset($map[$category])) {
            throw new RuntimeException('UNKNOWN_CATEGORY');
        }

        return $map[$category];
    }

    /**
     * @return array<string, DestructionHandlerInterface>
     */
    public static function all()
    {
        if (self::$handlers !== null) {
            return self::$handlers;
        }

        $policy = static function ($category, $reason) {
            return new PolicyRequiredDestructionHandler($category, $reason);
        };

        $list = [
            new PersonelOzlukDestructionHandler(),
            new IseGirisCikisDestructionHandler(),
            new PersonelBelgeDestructionHandler(),
            new IzinDestructionHandler(),
            new OlayDestructionHandler(),
            new SavunmaDestructionHandler(),
            new OnayAuditDestructionHandler(),
            new PuantajDestructionHandler(),
            new BordroDestructionHandler(),
            new SgkEksikGunDestructionHandler(),
            $policy(
                RetentionCategories::FAZLA_CALISMA,
                'Shared haftalik_kapanis identity with SERBEST_ZAMAN — co-destroy vs category-scoped field policy unresolved'
            ),
            $policy(
                RetentionCategories::SERBEST_ZAMAN,
                'Shared haftalik_kapanis identity with FAZLA_CALISMA — co-destroy vs category-scoped field policy unresolved'
            ),
            $policy(
                RetentionCategories::DISIPLIN,
                'DISIPLIN surec FK-blocked by disiplin_vakalar; OLAY/SAVUNMA co-lifecycle policy unresolved'
            ),
            $policy(
                RetentionCategories::RAPOR,
                'Medical/SGK-linked surec destruction vs retain-evidence policy unresolved'
            ),
            $policy(
                RetentionCategories::IS_KAZASI,
                'Legal/accident surec + attachment scope policy unresolved'
            ),
        ];

        $map = [];
        foreach ($list as $handler) {
            $map[$handler->category()] = $handler;
        }

        // Fail-closed: every catalog category must have an entry.
        foreach (RetentionCategories::all() as $cat) {
            if (!isset($map[$cat])) {
                $map[$cat] = $policy($cat, 'No typed handler registered');
            }
        }

        self::$handlers = $map;

        return self::$handlers;
    }

    /** @internal tests */
    public static function resetForTests()
    {
        self::$handlers = null;
    }
}
