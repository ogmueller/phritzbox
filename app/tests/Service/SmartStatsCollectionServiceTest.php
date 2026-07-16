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

    public function testUsesBoxDatatimeForNewestTimestamp(): void
    {
        $ain = 'collect-datatime';
        // datatime 1784156459 = 2026-07-15 23:00:59 UTC. Floored to the 900s
        // grid that is 23:00:00 — the newest stored row must carry that instant
        // (the box's own clock), not a server-clock reconstruction one interval
        // behind. count=2 => a second row exactly one grid step earlier.
        $datatime = 1784156459;
        $grid = 900;
        $aha = $this->aha(
            [$this->device($ain, 'Sensor')],
            [$ain => ['temperature' => [[
                'interval' => $grid,
                'count' => 2,
                'datatime' => $datatime,
                'values' => [21.0, 22.0],
            ]]]],
        );

        $result = $this->service($aha)->collectAll();

        self::assertSame(2, $result['rows']);

        $floored = $datatime - ($datatime % $grid);
        // Render the expected timestamps with the same timezone the service uses
        // so the assertion holds regardless of the test host's local timezone.
        $expectedNewest = (new \DateTimeImmutable())->setTimestamp($floored)->format('Y-m-d H:i:s');
        $expectedOldest = (new \DateTimeImmutable())->setTimestamp($floored - $grid)->format('Y-m-d H:i:s');

        $conn = $this->em->getConnection();
        self::assertSame($expectedNewest, $conn->fetchOne('SELECT MAX(time) FROM smart_device_data WHERE sid = ?', [$ain]));
        self::assertSame($expectedOldest, $conn->fetchOne('SELECT MIN(time) FROM smart_device_data WHERE sid = ?', [$ain]));
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
