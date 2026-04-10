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

use App\Device\Feature\Temperature;
use PHPUnit\Framework\TestCase;

class TemperatureTest extends TestCase
{
    private function xml(string $inner): \SimpleXMLElement
    {
        return simplexml_load_string('<device>'.$inner.'</device>');
    }

    public function testSetXmlParsesValues(): void
    {
        $feature = new Temperature();
        $feature->setXml($this->xml('<temperature><celsius>245</celsius><offset>5</offset></temperature>'));

        self::assertSame(24.5, $feature->getTemperatureCelsius());
        self::assertSame(0.5, $feature->getTemperatureOffset());
    }

    public function testSetXmlMissingTemperatureNode(): void
    {
        $feature = new Temperature();
        $feature->setXml($this->xml(''));

        self::assertNull($feature->getTemperatureCelsius());
        self::assertNull($feature->getTemperatureOffset());
    }

    public function testSetXmlMissingOffsetField(): void
    {
        $feature = new Temperature();
        $feature->setXml($this->xml('<temperature><celsius>200</celsius></temperature>'));

        self::assertSame(20.0, $feature->getTemperatureCelsius());
        self::assertNull($feature->getTemperatureOffset());
    }

    public function testSetXmlMissingCelsiusField(): void
    {
        $feature = new Temperature();
        $feature->setXml($this->xml('<temperature><offset>10</offset></temperature>'));

        self::assertNull($feature->getTemperatureCelsius());
        self::assertSame(1.0, $feature->getTemperatureOffset());
    }

    public function testToArray(): void
    {
        $feature = new Temperature();
        $feature->setXml($this->xml('<temperature><celsius>230</celsius><offset>0</offset></temperature>'));

        $result = $feature->toArray();
        self::assertArrayHasKey('temperatureCelsius', $result);
        self::assertArrayHasKey('temperatureOffset', $result);
        self::assertSame(23.0, $result['temperatureCelsius']);
        self::assertSame(0.0, $result['temperatureOffset']);
    }
}
