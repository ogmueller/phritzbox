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
use App\Client\Helper as ClientHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class Helper extends TestCase
{
    public static function mockClientHelper(TestCase $test, string $response): AhaApi
    {
        // A stub (not a mock) — we only need canned return values, no call-count
        // expectations. Mock objects imply an any() expectation, deprecated in PHPUnit 13.
        $helper = $test->createStub(ClientHelper::class);

        $client = new MockHttpClient(new MockResponse($response));
        $helper->method('requestUrl')->willReturn($client->request('GET', 'https://fritz.box'));
        $helper->method('getSid')->willReturn('123');
        $helper->method('getUrlAha')->willReturn('https://fritz.box');

        return new AhaApi($helper, new NullLogger());
    }
}
