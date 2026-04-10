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

namespace App\Tests\Command;

use App\Command\Smart;
use App\Command\SmartDeviceList;
use App\Device;
use Symfony\Component\Console\Command\Command;

class SmartDeviceListTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartDeviceList($this->ahaApi, $this->entityManager, $this->cache);
    }

    private function makeDevice(string $ain, string $name): Device
    {
        $device = new Device();
        $device->setIdentifier($ain)
               ->setName($name)
               ->setManufacturer('AVM')
               ->setFirmwareVersion('04.27')
               ->setId('1')
               ->setFunctionBitMask(0)
               ->setPresent(true);

        return $device;
    }

    public function testListsDevicesInTable(): void
    {
        $devices = [
            $this->makeDevice('11630 0103875', 'Living Room'),
            $this->makeDevice('08761 0372830', 'Kitchen'),
        ];
        $this->ahaApi->method('getDeviceListInfos')->willReturn($devices);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Living Room', $tester->getDisplay());
        self::assertStringContainsString('Kitchen', $tester->getDisplay());
        self::assertStringContainsString('2 Devices found', $tester->getDisplay());
    }

    public function testEmptyDeviceList(): void
    {
        $this->ahaApi->method('getDeviceListInfos')->willReturn([]);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0 Devices found', $tester->getDisplay());
    }
}
