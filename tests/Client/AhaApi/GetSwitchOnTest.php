<?php

namespace App\Tests\Client\AhaApi;

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

        self::assertEquals('1', $return);
    }
}
