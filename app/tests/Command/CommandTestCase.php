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

use App\Client\AhaApi;
use App\Command\Smart;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

abstract class CommandTestCase extends TestCase
{
    protected AhaApi&MockObject $ahaApi;
    protected EntityManagerInterface&MockObject $entityManager;
    protected ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->ahaApi = $this->createMock(AhaApi::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->cache = new ArrayAdapter();
    }

    abstract protected function createCommand(): Smart;

    protected function runCommand(array $input = [], array $options = []): CommandTester
    {
        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $tester->execute($input, $options + ['capture_stderr_separately' => true]);

        return $tester;
    }
}
