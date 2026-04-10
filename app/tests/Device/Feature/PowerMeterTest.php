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

use App\Device\Feature\PowerMeter;
use PHPUnit\Framework\TestCase;

class PowerMeterTest extends TestCase
{
    private function xml(string $inner): \SimpleXMLElement
    {
        return simplexml_load_string('<device>'.$inner.'</device>');
    }

    public function testSetXmlParsesValues(): void
    {
        $feature = new PowerMeter();
        $feature->setXml($this->xml(
            '<powermeter><voltage>232000</voltage><power>110000</power><energy>87521000</energy></powermeter>'
        ));

        self::assertSame(232.0, $feature->getPowerMeterVoltage());
        self::assertSame(110.0, $feature->getPowerMeterPower());
        self::assertSame(87521.0, $feature->getPowerMeterEnergy());
    }

    public function testSetXmlMissingPowermeterNode(): void
    {
        $feature = new PowerMeter();
        $feature->setXml($this->xml(''));

        self::assertNull($feature->getPowerMeterVoltage());
        self::assertNull($feature->getPowerMeterPower());
        self::assertNull($feature->getPowerMeterEnergy());
    }

    public function testSetXmlZeroValues(): void
    {
        $feature = new PowerMeter();
        $feature->setXml($this->xml(
            '<powermeter><voltage>0</voltage><power>0</power><energy>0</energy></powermeter>'
        ));

        self::assertSame(0.0, $feature->getPowerMeterVoltage());
        self::assertSame(0.0, $feature->getPowerMeterPower());
        self::assertSame(0.0, $feature->getPowerMeterEnergy());
    }

    public function testToArray(): void
    {
        $feature = new PowerMeter();
        $feature->setXml($this->xml(
            '<powermeter><voltage>230000</voltage><power>50000</power><energy>1000</energy></powermeter>'
        ));

        $result = $feature->toArray();
        self::assertArrayHasKey('powerMeterVoltage', $result);
        self::assertArrayHasKey('powerMeterPower', $result);
        self::assertArrayHasKey('powerMeterEnergy', $result);
        self::assertSame(230.0, $result['powerMeterVoltage']);
        self::assertSame(50.0, $result['powerMeterPower']);
        self::assertSame(1.0, $result['powerMeterEnergy']);
    }
}
