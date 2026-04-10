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

class SetSrcOnTest extends TestCase
{
    public function testSuccess(): void
    {
        $aha = \App\Tests\Helper::mockClientHelper($this, "254\n");

        self::assertSame('254', $aha->setSrcOn('123'));
    }
}
