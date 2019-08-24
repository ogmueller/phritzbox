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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to list all know outlets
 *
 * To use this command, open a terminal window, enter into your project
 * directory and execute the following:
 *
 *     $ php bin/console smart:switch:on
 *
 * To output detailed information, increase the command verbosity:
 *
 *     $ php bin/console smart:switch:on -vv
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartSwitchPresent extends Smart
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:switch:present';

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
            ->setDescription('Determine availability of a SmartHome outlet')
            ->setHelp($this->getCommandHelp())
            ->addArgument('ain', InputArgument::REQUIRED, 'Actor identification number');
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
        $state = $this->ahaApi->getSwitchPresent($ain);

        if ($state === '1') {
            $this->io->writeln('Switch '.$ain.' is <fg=green>present</>');
        } elseif ($state === '0') {
            $this->io->writeln('Switch '.$ain.' is now <fg=red>disconnected</>');
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
The <info>%command.name%</info> command creates new users and saves them in the database:

  <info>php %command.full_name%</info> <comment>username password email</comment>

By default the command creates regular users. To create administrator users,
add the <comment>--admin</comment> option:

  <info>php %command.full_name%</info> username password email <comment>--admin</comment>

If you omit any of the three required arguments, the command will ask you to
provide the missing values:

  # command will ask you for the email
  <info>php %command.full_name%</info> <comment>username password</comment>

  # command will ask you for the email and password
  <info>php %command.full_name%</info> <comment>username</comment>

  # command will ask you for all arguments
  <info>php %command.full_name%</info>

HELP;
    }
}
