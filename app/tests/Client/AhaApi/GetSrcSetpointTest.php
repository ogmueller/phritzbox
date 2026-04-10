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

class GetSrcSetpointTest extends TestCase
{
    public function testSuccess(): void
    {
        $aha = \App\Tests\Helper::mockClientHelper($this, "210\n");

        self::assertSame('210', $aha->getSrcSetpoint('123'));
    }
}
