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

use PHPUnit\Framework\TestCase;

class SetSrcSetpointTest extends TestCase
{
    public function testSuccess(): void
    {
        // 21°C → clamped to 21, multiplied by 2 → 42 raw value echoed back
        $aha = \App\Tests\Helper::mockClientHelper($this, "42\n");

        self::assertSame('42', $aha->setSrcSetpoint('123', 21));
    }

    public function testClampsToMinimum(): void
    {
        $aha = \App\Tests\Helper::mockClientHelper($this, "16\n");

        // value below 8°C should be clamped to 8 → 16 raw
        self::assertSame('16', $aha->setSrcSetpoint('123', 1));
    }

    public function testClampsToMaximum(): void
    {
        $aha = \App\Tests\Helper::mockClientHelper($this, "56\n");

        // value above 28°C should be clamped to 28 → 56 raw
        self::assertSame('56', $aha->setSrcSetpoint('123', 99));
    }
}
