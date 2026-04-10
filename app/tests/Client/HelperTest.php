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

namespace App\Tests\Client;

use App\Client\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HelperTest extends TestCase
{
    public static function bestFactorProvider(): array
    {
        // The function divides milliValue by 2 before choosing a prefix,
        // so the threshold to advance to the next prefix is 2000 (not 1000).
        // factor returned is 1000^base, where base is floor(log(milliValue/2, 1000)).
        // factor is 1000 ** base; base comes from floor() so it is float for non-zero inputs,
        // but plain int 0 for the zero-input path (base never enters the floor() branch).
        return [
            // 0 → base stays int 0, factor = 1000 ** 0 = 1 (int)
            'zero value'     => [0.0,             'W', 0.0,   'mW', 1],
            // 500 mW → base=0.0 (float), factor = 1000 ** 0.0 = 1.0 (float)
            'stays milli'    => [500.0,           'W', 500.0, 'mW', 1.0],
            // 2000 mW → base=1.0, factor = 1000.0
            'steps to watts' => [2000.0,          'W', 2.0,   'W',  1000.0],
            // 2,000,000 mW → base=2.0, factor = 1_000_000.0
            'kilo range'     => [2_000_000.0,     'W', 2.0,   'kW', 1_000_000.0],
            // 2,000,000,000 mW → base=3.0, factor = 1_000_000_000.0
            'mega range'     => [2_000_000_000.0, 'W', 2.0,   'MW', 1_000_000_000.0],
        ];
    }

    #[DataProvider('bestFactorProvider')]
    public function testBestFactor(float $input, string $unit, float $expectedValue, string $expectedUnit, int|float $expectedFactor): void
    {
        $result = Helper::bestFactor($input, $unit);

        self::assertSame($expectedValue, $result['value']);
        self::assertSame($expectedUnit, $result['unit']);
        self::assertSame($expectedFactor, $result['factor']);
    }
}
