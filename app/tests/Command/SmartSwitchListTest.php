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
use App\Command\SmartSwitchList;
use Symfony\Component\Console\Command\Command;

class SmartSwitchListTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchList($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testListsSwitches(): void
    {
        $this->ahaApi->method('getSwitchList')->willReturn(['11630 0103875', '08761 0372830']);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('11630 0103875', $tester->getDisplay());
        self::assertStringContainsString('08761 0372830', $tester->getDisplay());
    }

    public function testErrorOnEmptyList(): void
    {
        $this->ahaApi->method('getSwitchList')->willReturn([]);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testErrorOnNullResponse(): void
    {
        $this->ahaApi->method('getSwitchList')->willReturn(null);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
