<?php

namespace App\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchList extends TestCase
{
    public function testSuccess()
    {
        $response = "087610372823,grp1DA951-3A2C30F97,24:65:11:CA:3F:81\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchList();

        $this->assertEquals(
            [
                '087610372823',
                'grp1DA951-3A2C30F97',
                '24:65:11:CA:3F:81',
            ],
            $return
        );
    }
}
