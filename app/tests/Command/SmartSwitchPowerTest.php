<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\Smart;
use App\Command\SmartSwitchPower;
use Symfony\Component\Console\Command\Command;

class SmartSwitchPowerTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchPower($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsFormattedPower(): void
    {
        $this->ahaApi->method('getSwitchPower')->willReturn('11800');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // 11800 mW → bestFactor → 11.8 W
        self::assertStringContainsString('W', $tester->getDisplay());
    }

    public function testSimpleOutputRawMilliwatts(): void
    {
        $this->ahaApi->method('getSwitchPower')->willReturn('11800');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('11800', $tester->getDisplay());
    }

    public function testZeroPower(): void
    {
        $this->ahaApi->method('getSwitchPower')->willReturn('0');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testErrorOnNoDevice(): void
    {
        $this->ahaApi->method('getSwitchPower')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
