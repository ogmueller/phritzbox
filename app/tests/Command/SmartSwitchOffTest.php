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
use App\Command\SmartSwitchOff;
use Symfony\Component\Console\Command\Command;

class SmartSwitchOffTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchOff($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsOffState(): void
    {
        $this->ahaApi->method('setSwitchOff')->willReturn('0');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('OFF', $tester->getDisplay());
    }

    public function testSimpleOutput(): void
    {
        $this->ahaApi->method('setSwitchOff')->willReturn('0');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0', $tester->getDisplay());
    }

    public function testErrorOnUnknownSwitch(): void
    {
        $this->ahaApi->method('setSwitchOff')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
