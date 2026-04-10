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
use App\Command\SmartSrcSetpoint;
use Symfony\Component\Console\Command\Command;

class SmartSrcSetpointTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSrcSetpoint($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testReadsSetpoint(): void
    {
        // 42 raw = 21°C (42/2)
        $this->ahaApi->method('getSrcSetpoint')->willReturn('42');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('21', $tester->getDisplay());
        self::assertStringContainsString('°C', $tester->getDisplay());
    }

    public function testSimpleOutputOmitsUnit(): void
    {
        $this->ahaApi->method('getSrcSetpoint')->willReturn('42');

        $tester = $this->runCommand(['ain' => '123', '--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('°C', $tester->getDisplay());
    }

    public function testOutputsOffWhenValue253(): void
    {
        $this->ahaApi->method('getSrcSetpoint')->willReturn('253');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('OFF', $tester->getDisplay());
    }

    public function testOutputsOnWhenValue254(): void
    {
        $this->ahaApi->method('getSrcSetpoint')->willReturn('254');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ON', $tester->getDisplay());
    }

    public function testErrorOnNoDevice(): void
    {
        $this->ahaApi->method('getSrcSetpoint')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
