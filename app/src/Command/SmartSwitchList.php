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

namespace App\Command;

use App\Device;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to list all known outlets.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:switch:list', description: 'List all known SmartHome outlets')]
class SmartSwitchList extends Smart
{
    protected int $requiredFeatures = Device::FUNCTION_BIT_OUTLET;

    protected function configure(): void
    {
        $this->setHelp($this->getCommandHelp());
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch,
    ): int {
        $list = $this->ahaApi->getSwitchList();

        if ($list === null) {
            $errOutput->writeln('Could not retrieve switch list');

            return 1;
        }

        if (\count($list)) {
            foreach ($list as $ain) {
                $this->io->writeln($ain);
            }
            $returnCode = 0;
        } else {
            $errOutput->writeln('No switches available');
            $returnCode = 1;
        }

        return $returnCode;
    }

    /**
     * The command help is usually included in the configure() method, but when
     * it's too long, it's better to define a separate method to maintain the
     * code readability.
     */
    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command lists all known SmartHome outlets including groups:

  <info>php %command.full_name%</info>

HELP;
    }
}
