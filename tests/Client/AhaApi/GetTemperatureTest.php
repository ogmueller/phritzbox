<?php

namespace App\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetTemperatureTest extends TestCase
{
    public function testSuccess()
    {
        $response = "225\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getTemperature('123');

        $this->assertEquals('225', $return);
    }
}
