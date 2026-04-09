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
class GetSwitchListTest extends TestCase
{
    public function testSuccess()
    {
        $response = "087610372823,grp1DA951-3A2C30F97,24:65:11:CA:3F:81\n";
        $aha = \App\Tests\Helper::mockClientHelper($this, $response);
        $return = $aha->getSwitchList();

        self::assertSame(
            [
                '087610372823',
                'grp1DA951-3A2C30F97',
                '24:65:11:CA:3F:81',
            ],
            $return
        );
    }
}
