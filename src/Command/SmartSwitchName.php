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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to get name of outlets or group.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:switch:name')]
class SmartSwitchName extends Smart
{
    protected $requiredFeatures = Device::FUNCTION_BIT_OUTLET;

    protected function configure(): void
    {
        $this
            ->setDescription('Get name of a SmartHome outlet')
            ->setHelp($this->getCommandHelp())
            ->addArgument('ain', InputArgument::REQUIRED, 'Actor identification number')
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'Output name only'
            );
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch,
    ): int {
        $returnCode = 0;

        $ain = $input->getArgument('ain');
        $name = $this->ahaApi->getSwitchName($ain);

        if (!empty($name)) {
            if ($input->getOption('simple')) {
                $this->io->writeln($name);
            } else {
                $this->io->writeln('Switch '.$ain.' is called "'.$name.'"');
            }
        } else {
            $errOutput->writeln('Switches '.$ain.' is unknown');
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
The <info>%command.name%</info> command gets the name of a SmartHome outlet or group:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output the AIN and name of the outlet or group.

You can also use the <comment>-s</comment> option to get a simplified output:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

HELP;
    }
}
