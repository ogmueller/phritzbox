<?php

namespace App\Tests\Client;

use App\Client\AhaApi;
use App\Client\Helper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

        $helper->expects($this->any())
               ->method('requestUrl')
               ->withAnyParameters()
               ->willReturn($deviceXml);

        $helper->expects($this->any())
               ->method('getSid')
               ->willReturn(123);

        $x   = new AhaApi($helper);
        $bla = $x->getDeviceListInfos();
        var_export((array)$bla[0]);

        $this->assertSame($bla, '');
    }

    /**
     * @return \Generator
     */
    public function provideDevices()
    {
        // FRITZ!DECT 200
        yield [
            '<devicelist version="1"><device identifier="08761 0372830" id="16" functionbitmask="2944" fwversion="04.16" manufacturer="AVM" productname="FRITZ!DECT 200"><present>1</present><name>FRITZ!DECT 200 (Fan)</name><switch><state>0</state><mode>manuell</mode><lock>0</lock><devicelock>0</devicelock></switch><powermeter><voltage>227289</voltage><power>0</power><energy>86340</energy></powermeter><temperature><celsius>270</celsius><offset>0</offset></temperature></device></devicelist>',
            [],
        ];

        // FRITZ!Powerline 546E
        yield [
            '<devicelist version="1"><device identifier="24:65:11:CA:3F:43" id="20000" functionbitmask="640" fwversion="06.92" manufacturer="AVM" productname="FRITZ!Powerline 546E"><present>1</present><name>FRITZ!Powerline 546E</name><switch><state>1</state><mode>manuell</mode><lock>0</lock><devicelock>0</devicelock></switch><powermeter><voltage>229075</voltage><power>0</power><energy>8</energy></powermeter></device></devicelist>',
            [],
        ];

        // GROUP
        yield [
            '<devicelist version="1"><group identifier="grp1DA951-3A2C30AC7" id="900" functionbitmask="6784" fwversion="1.0" manufacturer="AVM" productname=""><present>1</present><name>Kühlen</name><switch><state>0</state><mode>manuell</mode><lock>0</lock><devicelock>0</devicelock></switch><powermeter><voltage>227289</voltage><power>0</power><energy>86340</energy></powermeter><groupinfo><masterdeviceid>0</masterdeviceid><members>16</members></groupinfo></group></devicelist>',
            [],
        ];
    }
}
