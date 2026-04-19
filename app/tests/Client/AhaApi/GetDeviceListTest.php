<?php

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Client\AhaApi;

use App\Client\AhaApi;
use App\Device;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks.
 */
class GetDeviceListTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/../../fixtures/devices/';

    private static function loadFixture(string $filename): string
    {
        return '<devicelist version="1">'
            .file_get_contents(self::FIXTURES_DIR.$filename)
            .'</devicelist>';
    }

    #[DataProvider('provideDevices')]
    public function testDevices($deviceXml, $expected)
    {
        $aha = \App\Tests\Helper::mockClientHelper($this, $deviceXml);
        $devices = $aha->getDeviceListInfos();

        self::assertCount(1, $devices);

        /** @var Device $device */
        $device = reset($devices);
        self::assertSame($expected, $device->toArray());
    }

    /**
     * @return \Generator
     */
    public static function provideDevices()
    {
        // FRITZ!DECT 200
        yield [
            self::loadFixture('fritz-dect-200.xml'),
            [
                'firmwareVersion' => '04.16',
                'functionBitMask' => 2944,
                'id' => '16',
                'identifier' => '08761 0372830',
                'manufacturer' => 'AVM',
                'name' => 'FRITZ!DECT 200 (Fan)',
                'present' => true,
                'productName' => 'FRITZ!DECT 200',
                'powerMeterEnergy' => 12345.678,
                'powerMeterPower' => 123.456,
                'powerMeterVoltage' => 1.234,
                'switchDeviceLock' => true,
                'switchLock' => true,
                'switchMode' => 'manual',
                'switchState' => true,
                'temperatureCelsius' => 12.3,
                'temperatureOffset' => 0.5,
            ],
        ];

        yield [
            self::loadFixture('fritz-dect-200-off.xml'),
            [
                'firmwareVersion' => '04.16',
                'functionBitMask' => 2944,
                'id' => '16',
                'identifier' => '08761 0372830',
                'manufacturer' => 'AVM',
                'name' => 'FRITZ!DECT 200 (Fan)',
                'present' => false,
                'productName' => 'FRITZ!DECT 200',
                'powerMeterEnergy' => 0.0,
                'powerMeterPower' => 0.0,
                'powerMeterVoltage' => 0.0,
                'switchDeviceLock' => false,
                'switchLock' => false,
                'switchMode' => 'manual',
                'switchState' => false,
                'temperatureCelsius' => 0.0,
                'temperatureOffset' => -1.0,
            ],
        ];

        // FRITZ!Powerline 546E
        yield [
            self::loadFixture('fritz-powerline-546e.xml'),
            [
                'firmwareVersion' => '06.92',
                'functionBitMask' => 640,
                'id' => '20000',
                'identifier' => '24:65:11:CA:3F:43',
                'manufacturer' => 'AVM',
                'name' => 'FRITZ!Powerline 546E',
                'present' => true,
                'productName' => 'FRITZ!Powerline 546E',
                'powerMeterEnergy' => 0.008,
                'powerMeterPower' => 0.0,
                'powerMeterVoltage' => 229.075,
                'switchDeviceLock' => false,
                'switchLock' => false,
                'switchMode' => 'manual',
                'switchState' => true,
            ],
        ];
    }
}
