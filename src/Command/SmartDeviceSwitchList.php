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

use App\Client\AhaApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
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
 *     $ php bin/console smart:device:switchlist
 *
 * To output detailed information, increase the command verbosity:
 *
 *     $ php bin/console smart:device:switchlist -vv
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartDeviceSwitchList extends Command
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:device:switchlist';

    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var AhaApi
     */
    private $ahaApi;

    public function __construct(AhaApi $ahaApi)
    {
        parent::__construct();

        $this->ahaApi = $ahaApi;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('List all known SmartHome outlets')
            ->setHelp($this->getCommandHelp());
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

    /**
     * This method is executed after initialize() and before execute(). Its purpose
     * is to check if some of the options/arguments are missing and interactively
     * ask the user for those values.
     *
     * This method is completely optional. If you are developing an internal console
     * command, you probably should not implement this method because it requires
     * quite a lot of work. However, if the command is meant to be used by external
     * users, this method is a nice way to fall back and prevent errors.
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
    }

    /**
     * This method is executed after interact() and initialize(). It usually
     * contains the logic to execute to complete this command task.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start(self::$defaultName);

        $errOutput  = $output->getErrorOutput();
        $returnCode = 0;

        if ($output->isVerbose()) {
            $progress = new ProgressIndicator($output);
            $progress->start('Fetching data...');
        }

        $list = $this->ahaApi->getSwitchList();

        $output->isVerbose() && $progress->finish('Done');

        if (count($list)) {
            foreach ($list as $ain) {
                $this->io->writeln($ain);
            }
        } else {
            $errOutput->writeln('No switches available');
            $returnCode = 1;
        }

        $event = $stopwatch->stop(self::$defaultName);

        if ($output->isVeryVerbose()) {
            $this->io->comment(
                sprintf(
                    'SmartHome SwitchList: Elapsed time: %.2f ms / Consumed memory: %.2f MB',
                    $event->getDuration(),
                    $event->getMemory() / (1024 ** 2)
                )
            );
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
