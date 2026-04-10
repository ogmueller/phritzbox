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
use App\Command\SmartSrcComfort;
use Symfony\Component\Console\Command\Command;

class SmartSrcComfortTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartSrcComfort($this->ahaApi, $this->entityManager, $this->cache);
    }

    public function testOutputsComfortTemperature(): void
    {
        // 44 raw = 22°C
        $this->ahaApi->method('getSrcComfort')->willReturn('44');

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('22', $tester->getDisplay());
        self::assertStringContainsString('°C', $tester->getDisplay());
    }

    public function testErrorOnNoDevice(): void
    {
        $this->ahaApi->method('getSrcComfort')->willReturn(null);

        $tester = $this->runCommand(['ain' => '123']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
