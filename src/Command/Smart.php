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

use App\Client\AhaApi;
use App\Device;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Abstract class to provide general functionality.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
abstract class Smart extends Command
{
    protected SymfonyStyle $io;

    /**
     * Minimum required feature of device.
     *
     * Example: If we want to turn on/off a device, it has to be
     * a \App\Device::FUNCTION_BIT_OUTLET.
     */
    protected int $requiredFeatures = -1;

    public function __construct(
        protected AhaApi $ahaApi,
        protected EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.app')]
        protected CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    /**
     * Prevent failure in case necessary arguments are missing.
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        // if command requires an AIN, but none give, we want to ask for it
        if ($input->hasArgument('ain') && empty($input->getArgument('ain'))) {
            // fetch all available AINs
            $valueItem = $this->cache->getItem('app.smart.devices');
            if (!$valueItem->isHit()) {
                $valueItem->set($this->ahaApi->getDeviceListInfos())
                          ->expiresAfter(900);
                $this->cache->save($valueItem);
            }
            $devices = $valueItem->get();

            $helper = $this->getHelper('question');
            $question = new Question('Please enter AIN of device: ');

            if (!empty($devices)) {
                $rows = [];
                $availableAin = [];

                /** @var Device $device */
                foreach ($devices as $device) {
                    // check if this device matches the minimum required features
                    if ($this->requiredFeatures <= 0 || ($this->requiredFeatures & $device->getFunctionBitMask(
                    )) === $this->requiredFeatures) {
                        $identifier = $device->getIdentifier();
                        $display = $device->isPresent() ? '<fg=green>'.$identifier.'</>' : $identifier;
                        $rows[] = [
                            $display,
                            $device->getName(),
                            $device->hasTemperature() ? 'x' : '-',
                            $device->hasOutlet() ? 'x' : '-',
                            $device->hasPowerMeter() ? 'x' : '-',
                        ];
                        $availableAin[] = $identifier;
                    }
                }

                if (!empty($rows)) {
                    $table = new Table($output);
                    $table->setHeaders(['Identifier (AIN)', 'Name', 'Temp', 'Switch', 'Power']);

                    $centered = new TableStyle();
                    $centered->setPadType(\STR_PAD_BOTH);
                    $table->setColumnStyle(2, $centered);
                    $table->setColumnStyle(3, $centered);
                    $table->setColumnStyle(4, $centered);
                    $table->addRows($rows);
                    $table->render();
                    $this->io->writeln('');
                }

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
     */
    protected function initialize(
        InputInterface $input,
        OutputInterface $output,
    ): void {
        // SymfonyStyle is an optional feature that Symfony provides so you can
        // apply a consistent look to the commands of your application.
        // See https://symfony.com/doc/current/console/style.html
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * This method is executed after interact() and initialize(). It usually
     * contains the logic to execute to complete this command task.
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $commandName = $this->getName() ?? 'unknown';
        $stopwatch = new Stopwatch();
        $stopwatch->start($commandName);

        /** @var ConsoleOutput $output */
        $errOutput = $output->getErrorOutput();
        $returnCode = 0;

        if ($output->isVerbose()) {
            $progress = new ProgressIndicator($output);
            $progress->start('Fetching data...');
        }

        $this->executeSmart($input, $output, $errOutput, $stopwatch);

        $output->isVerbose() && $progress->finish('Done');
        $event = $stopwatch->stop($commandName);

        if ($output->isVeryVerbose()) {
            $this->io->comment(
                \sprintf(
                    'Command '.$commandName.': Elapsed time: %.2f ms / Consumed memory: %.2f MB',
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
        Stopwatch $stopwatch,
    ): int;
}
