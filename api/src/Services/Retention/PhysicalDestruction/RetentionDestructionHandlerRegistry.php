<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\BordroDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\DisiplinDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\FazlaCalismaDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\IseGirisCikisDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\IsKazasiDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\IzinDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\OlayDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\OnayAuditDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PersonelBelgeDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PersonelOzlukDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PolicyRequiredDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PuantajDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\RaporDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\SavunmaDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\SerbestZamanDestructionHandler;
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
            new FazlaCalismaDestructionHandler(),
            new SerbestZamanDestructionHandler(),
            new DisiplinDestructionHandler(),
            new RaporDestructionHandler(),
            new IsKazasiDestructionHandler(),
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
