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
use App\Command\SmartSwitchPresent;
use Symfony\Component\Console\Command\Command;

class SmartSwitchPresentTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchPresent($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testPresent(): void
    {
        $this->ahaApi->method('getSwitchPresent')->willReturn('1');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('present', $tester->getDisplay());
    }

    public function testNotPresent(): void
    {
        $this->ahaApi->method('getSwitchPresent')->willReturn('0');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('disconnected', $tester->getDisplay());
    }

    public function testSimpleOutput(): void
    {
        $this->ahaApi->method('getSwitchPresent')->willReturn('1');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    public function testErrorOnUnknownSwitch(): void
    {
        $this->ahaApi->method('getSwitchPresent')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
