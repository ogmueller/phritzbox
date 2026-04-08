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
 * A console command to read setpoint for comfort temperature of a SmartHome smart radiator control
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:src:comfort')]
class SmartSrcComfort extends Smart
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
            ->setDescription('Read setpoint for comfort temperature of a SmartHome smart radiator control [°C]')
            ->setHelp($this->getCommandHelp())
            ->addArgument('ain', InputArgument::REQUIRED, 'Actor identification number')
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'Output without unit output'
            );
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch
    ): int {
        $returnCode = 0;

        $ain     = $input->getArgument('ain');
        $celsius = $this->ahaApi->getSrcComfort($ain);

        if (!empty($celsius)) {
            if ($celsius < 253) {
                $celsius = (int)$celsius / 2;
                if (!$input->getOption('simple')) {
                    $celsius .= '°C';
                }
                $this->io->writeln($celsius);
            } else {
                if (!$input->getOption('simple')) {
                    if($celsius == 253) {
                        $this->io->writeln('Smart radiator control '.$ain.' is <fg=red>OFF</>');
                    } else {
                        $this->io->writeln('Smart radiator control '.$ain.' is <fg=green>ON</>');
                    }
                } else {
                    $this->io->writeln($celsius == 253 ? 'OFF' : 'ON');
                }
            }
        } else {
            $errOutput->writeln('No temperature available on that device');
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
The <info>%command.name%</info> command reads setpoint for comfort temperature of a SmartHome smart radiator control:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output temperature with its unit. Temperature range is 8 - 28°C in 0.5°C steps.

You can also use the <comment>-s</comment> option to get a simplified output in [°C]:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

HELP;
    }
}
