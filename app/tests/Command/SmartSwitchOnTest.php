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
use App\Command\SmartSwitchOn;
use Symfony\Component\Console\Command\Command;

class SmartSwitchOnTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchOn($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsOnState(): void
    {
        $this->ahaApi->method('setSwitchOn')->willReturn('1');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ON', $tester->getDisplay());
    }

    public function testSimpleOutput(): void
    {
        $this->ahaApi->method('setSwitchOn')->willReturn('1');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    public function testErrorOnUnknownSwitch(): void
    {
        $this->ahaApi->method('setSwitchOn')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
