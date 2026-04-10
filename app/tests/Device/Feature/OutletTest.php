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

use App\Device\Feature\Outlet;
use PHPUnit\Framework\TestCase;

class OutletTest extends TestCase
{
    private function xml(string $inner): \SimpleXMLElement
    {
        return simplexml_load_string('<device>'.$inner.'</device>');
    }

    public function testSetXmlParsesValues(): void
    {
        $feature = new Outlet();
        $feature->setXml($this->xml(
            '<switch><state>1</state><mode>manual</mode><lock>0</lock><devicelock>1</devicelock></switch>'
        ));

        self::assertTrue($feature->isSwitchState());
        self::assertSame('manual', $feature->getSwitchMode());
        self::assertFalse($feature->isSwitchLock());
        self::assertTrue($feature->isSwitchDeviceLock());
    }

    public function testGermanModeNormalized(): void
    {
        $feature = new Outlet();
        $feature->setXml($this->xml('<switch><mode>manuell</mode></switch>'));

        self::assertSame('manual', $feature->getSwitchMode());
    }

    public function testAutoMode(): void
    {
        $feature = new Outlet();
        $feature->setXml($this->xml('<switch><mode>auto</mode></switch>'));

        self::assertSame('auto', $feature->getSwitchMode());
    }

    public function testUnknownModeThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $feature = new Outlet();
        $feature->setSwitchMode('bogus');
    }

    public function testSetXmlMissingSwitchNode(): void
    {
        $feature = new Outlet();
        $feature->setXml($this->xml(''));

        self::assertNull($feature->isSwitchState());
        self::assertNull($feature->getSwitchMode());
        self::assertNull($feature->isSwitchLock());
        self::assertNull($feature->isSwitchDeviceLock());
    }

    public function testToArray(): void
    {
        $feature = new Outlet();
        $feature->setXml($this->xml(
            '<switch><state>0</state><mode>auto</mode><lock>0</lock><devicelock>0</devicelock></switch>'
        ));

        $result = $feature->toArray();
        self::assertArrayHasKey('switchState', $result);
        self::assertArrayHasKey('switchMode', $result);
        self::assertArrayHasKey('switchLock', $result);
        self::assertArrayHasKey('switchDeviceLock', $result);
    }
}
