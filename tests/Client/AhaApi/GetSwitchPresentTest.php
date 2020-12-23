<?php

namespace App\Tests\Client\AhaApi;

use PHPUnit\Framework\TestCase;

/**
 * AhaApi tests with mocks
 */
class GetSwitchPresentTest extends TestCase
{
    public function testSuccess()
    {
        $response = "1\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchPresent('123');

        self::assertEquals('1', $return);

        $response = "0\n";
        $aha      = \App\Tests\Helper::mockClientHelper($this, $response);
        $return   = $aha->getSwitchPresent('123');

        self::assertEquals('0', $return);
    }
}
