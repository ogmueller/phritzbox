<?php

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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * A console command to turn on a SmartHome smart radiator control
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:src:on')]
class SmartSrcOn extends Smart
{
    /**
     * {@inheritdoc}
     */
    protected $requiredFeatures = Device::FUNCTION_BIT_THERMOSTAT;

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Turn on a SmartHome smart radiator control')
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
        Stopwatch $stopwatch
    ): int {
        $returnCode = 0;

        $ain   = $input->getArgument('ain');
        $state = $this->ahaApi->setSrcOn($ain);

        if ($state === '1') {
            if ($input->getOption('simple')) {
                $this->io->writeln($state);
            } else {
                $this->io->writeln('Smart radiator control '.$ain.' is now <fg=green>ON</>');
            }
        } else {
            $errOutput->writeln('No smart radiator control available');
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
The <info>%command.name%</info> command turns on a SmartHome smart radiator control:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output the AIN and state of the smart radiator control.

You can also use the <comment>-s</comment> option to get a simplified output as binary:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

HELP;
    }
}
