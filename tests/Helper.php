<?php

namespace App\Tests;

use App\Client\AhaApi;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class Helper
{
    public static function mockClientHelper(TestCase $test, string $response): AhaApi
    {
        /** @var MockObject|\App\Client\Helper $helper */
        $helper = $test->getMockBuilder(\App\Client\Helper::class)
                       ->setMethods(['requestUrl', 'getSid'])
                       ->getMock();

        $client = new MockHttpClient(new MockResponse($response));
        $helper->expects($test->any())
               ->method('requestUrl')
               ->withAnyParameters()
               ->willReturn($client->request('GET', 'https://fritz.box'));

        $helper->expects($test->any())
               ->method('getSid')
               ->willReturn(123);

        return new AhaApi($helper);
    }
}
