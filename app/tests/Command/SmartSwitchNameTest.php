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
use App\Command\SmartSwitchName;
use Symfony\Component\Console\Command\Command;

class SmartSwitchNameTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchName($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsNameWithLabel(): void
    {
        $this->ahaApi->method('getSwitchName')->willReturn('Living Room');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Living Room', $tester->getDisplay());
        self::assertStringContainsString('123', $tester->getDisplay());
    }

    public function testSimpleOutputNameOnly(): void
    {
        $this->ahaApi->method('getSwitchName')->willReturn('Living Room');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Living Room', $tester->getDisplay());
    }

    public function testErrorOnUnknownSwitch(): void
    {
        $this->ahaApi->method('getSwitchName')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
