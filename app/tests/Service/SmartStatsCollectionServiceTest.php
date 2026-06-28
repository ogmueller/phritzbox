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

namespace App\Tests\Service;

use App\Client\AhaApi;
use App\Device;
use App\Service\SmartDeviceService;
use App\Service\SmartStatsCollectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SmartStatsCollectionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(AhaApi $aha): SmartStatsCollectionService
    {
        return new SmartStatsCollectionService($aha, $this->em, static::getContainer()->get(SmartDeviceService::class));
    }

    private function device(string $ain, string $name): Device
    {
        $xml = simplexml_load_string(\sprintf(
            '<device identifier="%s" id="1" functionbitmask="2944" fwversion="1.0" manufacturer="AVM" productname="FRITZ!DECT 200"><name>%s</name><present>1</present></device>',
            $ain,
            $name,
        ));

        return Device::xmlFactory($xml);
    }

    private function rowCount(string $ain): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data WHERE sid = ?',
            [$ain],
        );
    }

    /** @param array<string, mixed> $batch */
    private function aha(array $devices, array $batch): AhaApi
    {
        $aha = $this->createStub(AhaApi::class);
        $aha->method('getDeviceListInfos')->willReturn($devices);
        $aha->method('getBasicDeviceStatsBatch')->willReturn($batch);

        return $aha;
    }

    public function testCollectsAndStoresReadings(): void
    {
        $ain = 'collect-store';
        $aha = $this->aha(
            [$this->device($ain, 'Sensor')],
            [$ain => ['temperature' => [['interval' => 300, 'count' => 3, 'values' => [21.0, 21.5, 22.0]]]]],
        );

        $result = $this->service($aha)->collectAll();

        self::assertSame(1, $result['devices']);
        self::assertSame(3, $result['rows']);
        self::assertSame(3, $this->rowCount($ain));
    }

    public function testSecondRunIsIdempotent(): void
    {
        $ain = 'collect-idempotent';
        $batch = [$ain => ['temperature' => [['interval' => 300, 'count' => 3, 'values' => [21.0, 21.5, 22.0]]]]];

        $this->service($this->aha([$this->device($ain, 'Sensor')], $batch))->collectAll();
        // Same grid again: the UNIQUE (sid, type, time) index + INSERT OR IGNORE
        // (and the "only newer" guard) must not create duplicate rows.
        $second = $this->service($this->aha([$this->device($ain, 'Sensor')], $batch))->collectAll();

        self::assertSame(0, $second['rows'], 'a repeated grid must insert nothing');
        self::assertSame(3, $this->rowCount($ain), 'no duplicate rows');
    }

    public function testDeviceMissingFromBatchIsSkippedWithoutAbortingRun(): void
    {
        $ok = 'collect-ok';
        $missing = 'collect-missing';
        // Two devices, but the stats batch only returns data for one of them
        // (the other "failed" inside getBasicDeviceStatsBatch and was dropped).
        $aha = $this->aha(
            [$this->device($ok, 'Good'), $this->device($missing, 'Bad')],
            [$ok => ['temperature' => [['interval' => 300, 'count' => 2, 'values' => [19.0, 20.0]]]]],
        );

        $result = $this->service($aha)->collectAll();

        self::assertSame(2, $result['devices']);
        self::assertSame(2, $result['rows']);
        self::assertSame(2, $this->rowCount($ok));
        self::assertSame(0, $this->rowCount($missing));
    }
}
