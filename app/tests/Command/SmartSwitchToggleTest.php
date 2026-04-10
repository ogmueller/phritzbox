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
use App\Command\SmartSwitchToggle;
use Symfony\Component\Console\Command\Command;

class SmartSwitchToggleTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSwitchToggle($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testToggledOn(): void
    {
        $this->ahaApi->method('setSwitchToggle')->willReturn('1');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ON', $tester->getDisplay());
    }

    public function testToggledOff(): void
    {
        $this->ahaApi->method('setSwitchToggle')->willReturn('0');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('OFF', $tester->getDisplay());
    }

    public function testSimpleOutput(): void
    {
        $this->ahaApi->method('setSwitchToggle')->willReturn('1');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
    }

    public function testErrorOnUnknownSwitch(): void
    {
        $this->ahaApi->method('setSwitchToggle')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
