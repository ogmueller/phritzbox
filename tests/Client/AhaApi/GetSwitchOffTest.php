<?php

namespace App\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchOffTest extends TestCase
{
    public function testSuccess()
    {
        $response = "0\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->setSwitchOff('123');

        $this->assertEquals('0', $return);
    }
}
