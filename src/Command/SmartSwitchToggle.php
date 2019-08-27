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

/**
 * A console command to toggle power state of a SmartHome outlet or group
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartSwitchToggle extends Smart
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:switch:toggle';

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

        $ain   = $input->getArgument('ain');
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
