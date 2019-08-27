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
class SmartSwitchPower extends Smart
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:switch:power';

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
            ->setDescription('Read current power consumption of a SmartHome outlet [mW]')
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
        $milliWatt = $this->ahaApi->getSwitchPower($ain);

        if (!empty($milliWatt) || is_numeric($milliWatt)) {
            $milliWatt = (int)$milliWatt;

            if (!$input->getOption('simple')) {
                $helper    = new Helper();
                $best      = $helper->bestFactor($milliWatt, 'W');
                $milliWatt = $best['value'].' '.$best['unit'];
//                $milliWatt = $milliWatt.' '.$prefix[$base].'W';
            }
            $this->io->writeln($milliWatt);
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
The <info>%command.name%</info> command read current power consumption of a SmartHome outlet:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output current power consumption in the best readable unit.

You can also use the <comment>-s</comment> option to get a simplified output in [mW]:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

HELP;
    }
}
