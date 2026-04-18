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

namespace App\Tests;

use App\Client\AhaApi;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class Helper extends TestCase
{
    public static function mockClientHelper(TestCase $test, string $response): AhaApi
    {
        /** @var MockObject|\App\Client\Helper $helper */
        $helper = $test->getMockBuilder(\App\Client\Helper::class)
                       ->disableOriginalConstructor()
                       ->onlyMethods(['requestUrl', 'getSid', 'getUrlAha'])
                       ->getMock();

        $client = new MockHttpClient(new MockResponse($response));
        $helper->expects($test->any())
               ->method('requestUrl')
               ->withAnyParameters()
               ->willReturn($client->request('GET', 'https://fritz.box'));

        $helper->expects($test->any())
               ->method('getSid')
               ->willReturn('123');

        $helper->expects($test->any())
               ->method('getUrlAha')
               ->willReturn('https://fritz.box');

        return new AhaApi($helper, new NullLogger());
    }
}
