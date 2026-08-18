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

namespace App\Tests\Device\Feature;

use App\Device\Feature\Thermostat;
use PHPUnit\Framework\TestCase;

class ThermostatTest extends TestCase
{
    private function xml(string $inner): \SimpleXMLElement
    {
        $element = simplexml_load_string('<device>'.$inner.'</device>');
        if ($element === false) {
            self::fail('the fixture XML in this test does not parse');
        }

        return $element;
    }

    private function feature(string $inner): Thermostat
    {
        $feature = new Thermostat();
        $feature->setXml($this->xml($inner));

        return $feature;
    }

    public function testDecodesHalfDegreeSetpoints(): void
    {
        // 42 half-degrees = 21 °C, 44 = 22 °C, 32 = 16 °C.
        $f = $this->feature('<hkr><tsoll>42</tsoll><komfort>44</komfort><absenk>32</absenk></hkr>');

        self::assertSame(21.0, $f->getSetpointCelsius());
        self::assertSame(22.0, $f->getComfortCelsius());
        self::assertSame(16.0, $f->getSavingCelsius());
        self::assertSame(Thermostat::MODE_TEMPERATURE, $f->getMode());
    }

    public function testHalfDegreeStepIsPreserved(): void
    {
        // 43 half-degrees = 21.5 °C — the encoding's whole point.
        $f = $this->feature('<hkr><tsoll>43</tsoll></hkr>');

        self::assertSame(21.5, $f->getSetpointCelsius());
    }

    public function testOffSentinelIsNotATemperature(): void
    {
        // 253 means "valve closed". Decoding it as a temperature would report
        // 126.5 °C and light up every high-temperature alert in the app.
        $f = $this->feature('<hkr><tsoll>253</tsoll></hkr>');

        self::assertNull($f->getSetpointCelsius());
        self::assertSame(Thermostat::MODE_OFF, $f->getMode());
    }

    public function testMaxSentinelIsNotATemperature(): void
    {
        // 254 means "valve fully open", i.e. 127 °C if taken literally.
        $f = $this->feature('<hkr><tsoll>254</tsoll></hkr>');

        self::assertNull($f->getSetpointCelsius());
        self::assertSame(Thermostat::MODE_MAX, $f->getMode());
    }

    public function testSentinelsAlsoApplyToComfortAndSaving(): void
    {
        $f = $this->feature('<hkr><komfort>253</komfort><absenk>254</absenk></hkr>');

        self::assertNull($f->getComfortCelsius());
        self::assertNull($f->getSavingCelsius());
    }

    public function testParsesBatteryAndFlags(): void
    {
        $f = $this->feature(
            '<hkr><tsoll>42</tsoll><battery>65</battery><batterylow>1</batterylow>'
            .'<windowopenactiv>1</windowopenactiv><boostactive>0</boostactive>'
            .'<holidayactive>0</holidayactive><summeractive>1</summeractive>'
            .'<lock>1</lock><devicelock>0</devicelock><errorcode>3</errorcode></hkr>'
        );

        self::assertSame(65, $f->getBattery());
        self::assertTrue($f->isBatteryLow());
        self::assertTrue($f->isWindowOpen());
        self::assertFalse($f->isBoostActive());
        self::assertFalse($f->isHolidayActive());
        self::assertTrue($f->isSummerActive());
        self::assertTrue($f->isLock());
        self::assertFalse($f->isDeviceLock());
        self::assertSame(3, $f->getErrorCode());
    }

    public function testMissingHkrNodeLeavesEverythingNull(): void
    {
        $f = $this->feature('');

        self::assertNull($f->getSetpointCelsius());
        self::assertNull($f->getBattery());
        self::assertNull($f->isBatteryLow());
        self::assertNull($f->isWindowOpen());
        self::assertNull($f->getErrorCode());
    }

    public function testOlderFirmwareWithoutBatteryPercentage(): void
    {
        // Pre-7.x FRITZ!OS reports only the low flag, never a percentage.
        $f = $this->feature('<hkr><tsoll>42</tsoll><batterylow>0</batterylow></hkr>');

        self::assertNull($f->getBattery());
        self::assertFalse($f->isBatteryLow());
    }

    public function testZeroErrorCodeMeansNoError(): void
    {
        $f = $this->feature('<hkr><errorcode>0</errorcode></hkr>');

        self::assertSame(0, $f->getErrorCode());
    }

    public function testToArrayExposesEveryField(): void
    {
        $array = $this->feature('<hkr><tsoll>42</tsoll><battery>80</battery></hkr>')->toArray();

        self::assertSame(21.0, $array['thermostatSetpoint']);
        self::assertSame(80, $array['thermostatBattery']);
        self::assertSame(Thermostat::MODE_TEMPERATURE, $array['thermostatMode']);
        self::assertArrayHasKey('thermostatWindowOpen', $array);
        self::assertArrayHasKey('thermostatErrorCode', $array);
    }
}
