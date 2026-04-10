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
use App\Command\SmartSwitchEnergy;
use Symfony\Component\Console\Command\Command;

class SmartSwitchEnergyTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchEnergy($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsFormattedEnergy(): void
    {
        $this->ahaApi->method('getSwitchEnergy')->willReturn('87521');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Wh', $tester->getDisplay());
    }

    public function testSimpleOutputRawWattHours(): void
    {
        $this->ahaApi->method('getSwitchEnergy')->willReturn('87521');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('87521', $tester->getDisplay());
    }

    public function testErrorOnNoDevice(): void
    {
        $this->ahaApi->method('getSwitchEnergy')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
