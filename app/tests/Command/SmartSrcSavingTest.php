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
use App\Command\SmartSrcSaving;
use Symfony\Component\Console\Command\Command;

class SmartSrcSavingTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSrcSaving($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsSavingTemperature(): void
    {
        // 36 raw = 18°C
        $this->ahaApi->method('getSrcSaving')->willReturn('36');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('18', $tester->getDisplay());
        self::assertStringContainsString('°C', $tester->getDisplay());
    }

    public function testErrorOnNoDevice(): void
    {
        $this->ahaApi->method('getSrcSaving')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
