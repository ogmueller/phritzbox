<?php

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Device;

use App\Device;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceParsingTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/../fixtures/devices/';

    private static function parseFixture(string $filename): Device
    {
        $xml = file_get_contents(self::FIXTURES_DIR.$filename);
        $element = simplexml_load_string('<devicelist version="1">'.$xml.'</devicelist>');

        return Device::xmlFactory($element->device[0]);
    }

    #[DataProvider('provideFixtures')]
    public function testFixtureParsesCorrectly(string $fixture, array $expected): void
    {
        $device = self::parseFixture($fixture);
        self::assertSame($expected, $device->toArray());
    }

    public static function provideFixtures(): \Generator
    {
        // FRITZ!DECT 200 — outlet + power meter + temperature sensor
        yield 'fritz-dect-200' => [
            'fritz-dect-200.xml',
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

        // FRITZ!DECT 200 — all values off/zero
        yield 'fritz-dect-200-off' => [
            'fritz-dect-200-off.xml',
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

        // FRITZ!Powerline 546E — outlet + power meter, no temperature sensor
        yield 'fritz-powerline-546e' => [
            'fritz-powerline-546e.xml',
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
