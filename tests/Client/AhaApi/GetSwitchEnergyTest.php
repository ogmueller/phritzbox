<?php

namespace App\Tests\Client\AhaApi;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchEnergyTest extends TestCase
{
    public function testSuccess()
    {
        $response = "87521\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchEnergy('123');

        self::assertEquals('87521', $return);

        $response = "0\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchEnergy('123');

        self::assertEquals('0', $return);
    }
}
