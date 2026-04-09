<?php

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

/**
 * AhaApi tests with mocks.
 */
class GetSwitchOffTest extends TestCase
{
    public function testSuccess()
    {
        $response = "0\n";
        $aha = \App\Tests\Helper::mockClientHelper($this, $response);
        $return = $aha->setSwitchOff('123');

        self::assertSame('0', $return);
    }
}
