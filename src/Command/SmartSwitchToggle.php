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
 * A console command to toggle power state of a SmartHome outlet or group.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:switch:toggle')]
class SmartSwitchToggle extends Smart
{
    protected $requiredFeatures = Device::FUNCTION_BIT_OUTLET;

    protected function configure(): void
    {
        $this
            ->setDescription('Toggle power state of a SmartHome outlet')
            ->setHelp($this->getCommandHelp())
            ->addArgument('ain', InputArgument::REQUIRED, 'Actor identification number')
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'Binary output 0/1'
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
        $state = $this->ahaApi->setSwitchToggle($ain);

        if (is_numeric($state)) {
            if ($input->getOption('simple')) {
                $this->io->writeln($state);
            } else {
                if ($state === '1') {
                    $this->io->writeln('Switch '.$ain.' is now <fg=green>ON</>');
                } else {
                    $this->io->writeln('Switch '.$ain.' is now <fg=red>OFF</>');
                }
            }
        } else {
            $errOutput->writeln('Switch '.$ain.' unknown');
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
The <info>%command.name%</info> command toggles power state of a SmartHome outlet or group:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output the AIN and state of the outlet or group.

You can also use the <comment>-s</comment> option to get a simplified output as binary:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

HELP;
    }
}
