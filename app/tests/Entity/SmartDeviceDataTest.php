<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Entity;

use App\Entity\SmartDeviceData;
use PHPUnit\Framework\TestCase;

class SmartDeviceDataTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $entity = new SmartDeviceData();

        self::assertNull($entity->getDataId());
    }

    public function testGettersAndSetters(): void
    {
        $time = new \DateTimeImmutable('2026-01-01 12:00:00');

        $entity = new SmartDeviceData();
        $result = $entity
            ->setSid('11630 0103875')
            ->setType('temperature')
            ->setTime($time)
            ->setValue(21.5);

        // fluent interface returns self
        self::assertSame($entity, $result);

        self::assertSame('11630 0103875', $entity->getSid());
        self::assertSame('temperature', $entity->getType());
        self::assertSame($time, $entity->getTime());
        self::assertSame(21.5, $entity->getValue());
    }
}
