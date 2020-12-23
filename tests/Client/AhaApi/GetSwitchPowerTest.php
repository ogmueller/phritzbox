<?php

namespace App\Tests\Client\AhaApi;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchPowerTest extends TestCase
{
    public function testSuccess()
    {
        $response = "11800\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchPower('123');

        self::assertEquals('11800', $return);

        $response = "0\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchPower('123');

        self::assertEquals('0', $return);
    }
}
