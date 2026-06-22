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

namespace App\Tests\Client\AhaApi;

use App\Client\AhaApi;
use App\Client\Helper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the concurrent (batched) device-stats fetch.
 */
class GetDeviceStatsBatchTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__.'/../../fixtures/stats/';

    public function testBatchParsesEachDeviceAndSkipsFailures(): void
    {
        $xml = file_get_contents(self::FIXTURES_DIR.'fritz-dect-200-stats.xml');

        $helper = $this->getMockBuilder(Helper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSid', 'getUrlAha', 'requestUrlsConcurrent'])
            ->getMock();
        $helper->method('getSid')->willReturn('123');
        $helper->method('getUrlAha')->willReturn('https://fritz.box');
        // Two devices succeed, one returns no body (failed request).
        $helper->expects(self::once())
            ->method('requestUrlsConcurrent')
            ->willReturn(['A' => $xml, 'B' => $xml, 'C' => null]);

        $aha = new AhaApi($helper, new NullLogger());
        $result = $aha->getBasicDeviceStatsBatch(['A', 'B', 'C']);

        // Failed device is skipped, successful ones are parsed.
        self::assertArrayHasKey('A', $result);
        self::assertArrayHasKey('B', $result);
        self::assertArrayNotHasKey('C', $result);

        // Parsing matches the single-fetch shape.
        self::assertSame(96, $result['A']['temperature'][0]['count']);
        self::assertSame(900, $result['A']['temperature'][0]['interval']);
        self::assertSame('V', $result['A']['voltage'][0]['unit']);
        self::assertSame($result['A'], $result['B']);
    }

    public function testEmptyAinListSkipsRequests(): void
    {
        $helper = $this->getMockBuilder(Helper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSid', 'getUrlAha', 'requestUrlsConcurrent'])
            ->getMock();
        $helper->expects(self::never())->method('requestUrlsConcurrent');

        $aha = new AhaApi($helper, new NullLogger());

        self::assertSame([], $aha->getBasicDeviceStatsBatch([]));
    }
}
