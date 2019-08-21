<?php

namespace App\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchOnTest extends TestCase
{
    public function testSuccess()
    {
        $response = "1\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->setSwitchOn('123');

        $this->assertEquals('1', $return);
    }
}
