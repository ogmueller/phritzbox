<?php

namespace App\Tests\Client;

use App\Client\AhaApi;
use App\Client\Helper;
use App\Device;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * AhaApi tests with mocks
 */
class AhaApiTest extends TestCase
{
    /**
     * @dataProvider provideDevices
     */
    public function testGetDeviceListInfos($deviceXml, $expected)
    {
        /** @var MockObject|Helper $helper */
        $helper = $this->getMockBuilder(Helper::class)
                       ->setMethods(['requestUrl', 'getSid'])
                       ->getMock();

        $client = new MockHttpClient(new MockResponse($deviceXml));
        $helper->expects($this->any())
               ->method('requestUrl')
               ->withAnyParameters()
               ->willReturn($client->request('GET','https://fritz.box'));

        $helper->expects($this->any())
               ->method('getSid')
               ->willReturn(123);

        $aha     = new AhaApi($helper);
        $devices = $aha->getDeviceListInfos();

        $this->assertCount(1, $devices);

        /** @var Device $device */
        $device = reset($devices);
        $this->assertSame($expected, $device->toArray());
    }

    /**
     * @return \Generator
     */
    public function provideDevices()
    {
        // FRITZ!DECT 200
        yield [
            '<devicelist version="1"><device identifier="08761 0372830" id="16" functionbitmask="2944" fwversion="04.16" manufacturer="AVM" productname="FRITZ!DECT 200"><present>1</present><name>FRITZ!DECT 200 (Fan)</name><switch><state>0</state><mode>manuell</mode><lock>0</lock><devicelock>0</devicelock></switch><powermeter><voltage>227289</voltage><power>0</power><energy>86340</energy></powermeter><temperature><celsius>270</celsius><offset>0</offset></temperature></device></devicelist>',
            [
                'firmwareVersion'    => '04.16',
                'functionBitMask'    => 2944,
                'id'                 => '16',
                'identifier'         => '08761 0372830',
                'manufacturer'       => 'AVM',
                'name'               => 'FRITZ!DECT 200 (Fan)',
                'present'            => true,
                'productName'        => 'FRITZ!DECT 200',
                'powerMeterEnergy'   => 86.34,
                'powerMeterPower'    => 0.0,
                'powerMeterVoltage'  => 227.289,
                'switchDeviceLock'   => true,
                'switchLock'         => true,
                'switchMode'         => 'manual',
                'switchState'        => true,
                'temperatureCelsius' => 27.0,
                'temperatureOffset'  => 0.0,
            ],
        ];

        // FRITZ!Powerline 546E
        yield [
            '<devicelist version="1"><device identifier="24:65:11:CA:3F:43" id="20000" functionbitmask="640" fwversion="06.92" manufacturer="AVM" productname="FRITZ!Powerline 546E"><present>1</present><name>FRITZ!Powerline 546E</name><switch><state>1</state><mode>manuell</mode><lock>0</lock><devicelock>0</devicelock></switch><powermeter><voltage>229075</voltage><power>0</power><energy>8</energy></powermeter></device></devicelist>',
            [
                'firmwareVersion'   => '06.92',
                'functionBitMask'   => 640,
                'id'                => '20000',
                'identifier'        => '24:65:11:CA:3F:43',
                'manufacturer'      => 'AVM',
                'name'              => 'FRITZ!Powerline 546E',
                'present'           => true,
                'productName'       => 'FRITZ!Powerline 546E',
                'powerMeterEnergy'  => 0.008,
                'powerMeterPower'   => 0.0,
                'powerMeterVoltage' => 229.075,
                'switchDeviceLock'  => true,
                'switchLock'        => true,
                'switchMode'        => 'manual',
                'switchState'       => true,
            ],
        ];
    }
}
