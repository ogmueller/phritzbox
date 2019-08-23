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
use App\Device;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Abstract class to provide general functionality
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
     * Prevent failure in case necessary arguments are missing
     *
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        // if command requires an AIN, but none give, we want to ask for it
        if ($input->hasArgument('ain') && empty($input->getArgument('ain'))) {
            // fetch all available AINs
            $cache   = new FilesystemAdapter();
            $devices = $cache->get(
                'app.smart.devices',
                function (ItemInterface $item) {
                    // cache should expire after 15min
                    $item->expiresAfter(900);

                    return $this->ahaApi->getDeviceListInfos();
                }
            );

            $helper   = $this->getHelper('question');
            $question = new Question('Please enter AIN of device: ');

            if (!empty($devices)) {
                $table = new Table($output);
                $rows  = [];

                /** @var Device $device */
                foreach ($devices as $device) {
                    $rows[] = [
                        $device->getIdentifier(),
                        $device->getName(),
                        $device->hasTemperature() ? 'x' : '-',
                        $device->hasOutlet() ? 'x' : '-',
                        $device->hasPowerMeter() ? 'x' : '-',
                    ];
                }

                $table->setHeaders(['Identifier (AIN)', 'Name', 'Temp', 'Switch', 'Power']);

                $centered = new TableStyle();
                $centered->setPadType(STR_PAD_BOTH);
                $table->setColumnStyle(2, $centered);
                $table->setColumnStyle(3, $centered);
                $table->setColumnStyle(4, $centered);
                $table->addRows($rows);
                $table->render();
                $this->io->writeln('');

                $availableAin = array_column($rows, 0);
                $question->setAutocompleterValues($availableAin);
            }

            while (empty($ain)) {
                $ain = $helper->ask($input, $output, $question);
            }
            $input->setArgument('ain', $ain);
        }
    }

    /**
     * This optional method is the first one executed for a command after configure()
     * and is useful to initialize properties based on the input arguments and options.
     *
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     */
    protected function initialize(
        InputInterface $input,
        OutputInterface $output
    ): void {
        // SymfonyStyle is an optional feature that Symfony provides so you can
        // apply a consistent look to the commands of your application.
        // See https://symfony.com/doc/current/console/style.html
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * This method is executed after interact() and initialize(). It usually
     * contains the logic to execute to complete this command task.
     *
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     * @return int
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $stopwatch = new Stopwatch();
        $stopwatch->start(self::$defaultName);

        /** @var ConsoleOutput $output */
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
