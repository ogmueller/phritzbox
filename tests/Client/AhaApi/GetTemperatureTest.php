<?php

namespace App\Tests\Client\AhaApi;

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

        self::assertEquals('225', $return);
    }
}
