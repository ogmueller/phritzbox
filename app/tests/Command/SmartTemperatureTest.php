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
use App\Command\SmartTemperature;
use Symfony\Component\Console\Command\Command;

class SmartTemperatureTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartTemperature($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsTemperatureWithUnit(): void
    {
        $this->ahaApi->method('getTemperature')->willReturn('245');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('24.5°C', $tester->getDisplay());
    }

    public function testSimpleOutputOmitsUnit(): void
    {
        $this->ahaApi->method('getTemperature')->willReturn('245');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('24.5', $tester->getDisplay());
        self::assertStringNotContainsString('°C', $tester->getDisplay());
    }

    public function testErrorWhenNoTemperature(): void
    {
        $this->ahaApi->method('getTemperature')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
