<?php

namespace App\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchNameTest extends TestCase
{
    public function testSuccess()
    {
        $response = "FRITZ!DECT 200\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchName('123');

        $this->assertEquals('FRITZ!DECT 200', $return);
    }
}
