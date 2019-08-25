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

use App\Client\Helper;
use App\Device;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to read out the temperature of a smart home device
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartSwitchEnergy extends Smart
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:switch:energy';

    /**
     * {@inheritdoc}
     */
    protected $requiredFeatures = Device::FUNCTION_BIT_OUTLET;

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Read energy quantity delivered over a SmartHome outlet [Wh]')
            ->setHelp($this->getCommandHelp())
            ->addArgument('ain', InputArgument::REQUIRED, 'Actor identification number')
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'Output without unit output'
            );
    }

    /**
     * This optional method is the first one executed for a command after configure()
     * and is useful to initialize properties based on the input arguments and options.
     *
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // SymfonyStyle is an optional feature that Symfony provides so you can
        // apply a consistent look to the commands of your application.
        // See https://symfony.com/doc/current/console/style.html
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch
    ): int {
        $returnCode = 0;

        $ain       = $input->getArgument('ain');
        $wattHours = $this->ahaApi->getSwitchEnergy($ain);
//        dump($wattHours);

        if (!empty($wattHours) || is_numeric($wattHours)) {
            $wattHours = (int)$wattHours;

            if (!$input->getOption('simple')) {
                $helper    = new Helper();
                $best      = $helper->bestFactor($wattHours * 1000, 'Wh');
                $wattHours = $best['value'].' '.$best['unit'];
            }
            $this->io->writeln($wattHours);
        } else {
            $errOutput->writeln('No energy quantity available on that device');
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
The <info>%command.name%</info> command read energy quantity delivered over a SmartHome outlet:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output the energy consumption in the best readable unit. The value
is the energy quantity since the first use of the device or last reset of energy statistic.

You can also use the <comment>-s</comment> option to get a simplified output in [Wh]:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment>

HELP;
    }
}
