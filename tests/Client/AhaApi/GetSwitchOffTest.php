<?php

namespace App\Tests\Client\AhaApi;

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

        self::assertEquals('0', $return);
    }
}
