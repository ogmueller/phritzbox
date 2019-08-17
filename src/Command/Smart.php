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
use PHPUnit\Framework\OutputError;
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
 *     $ php bin/console smart:switch:on
 *
 * To output detailed information, increase the command verbosity:
 *
 *     $ php bin/console smart:switch:on -vv
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
abstract class Smart extends Command
{
    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var AhaApi
     */
    protected $ahaApi;

    public function __construct(AhaApi $ahaApi)
    {
        parent::__construct();

        $this->ahaApi = $ahaApi;
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

        $this->executeSmart($input, $output, $errOutput, $stopwatch);

        $output->isVerbose() && $progress->finish('Done');
        $event = $stopwatch->stop(self::$defaultName);

        if ($output->isVeryVerbose()) {
            $this->io->comment(
                sprintf(
                    'Command '.self::$defaultName.': Elapsed time: %.2f ms / Consumed memory: %.2f MB',
                    $event->getDuration(),
                    $event->getMemory() / (1024 ** 2)
                )
            );
        }

        return $returnCode;
    }

    abstract protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch
    ): int;
}
