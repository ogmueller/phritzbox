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
use App\Client\Helper;
use App\Device;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to read out all available smart home devices
 *
 * To use this command, open a terminal window, enter into your project
 * directory and execute the following:
 *
 *     $ php bin/console smart:device:list
 *
 * To output detailed information, increase the command verbosity:
 *
 *     $ php bin/console smart:device:list -vv
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class ListSmartDevices extends Command
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:device:list';

    /**
     * @var SymfonyStyle
     */
    private $io;

//    public function __construct()
//    {
//        parent::__construct();
//    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('List all available SmartHome devices')
            ->setHelp($this->getCommandHelp())
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'No header output'
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
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start(self::$defaultName);

        if ($output->isVerbose()) {
            $progress = new ProgressIndicator($output);
            $progress->start('Fetching data...');
        }

        $simpleOutput = $input->getOption('simple');

        $sid     = Helper::getSid();
        $api     = new AhaApi($sid);
        $devices = $api->getDeviceListInfos();

        $output->isVerbose() && $progress->finish('Done');

        $table = new Table($output);
        $rows  = [];

        /** @var Device $device */
        foreach ($devices as $device) {
            $row = [
                $device->getIdentifier(),
                $device->getName(),
            ];

            if ($device->hasTemperature()) {
                $offset      = $device->getTemperatureOffset();
                $temperature = $device->getTemperatureCelsius() + $offset;
                $row[]       = sprintf('%02.1fC / %02.1fC', $temperature, $offset);
            } else {
                $row[] = '-';
            }

            if ($device->hasSwitch()) {
                $status = $device->isSwitchState();
                $row[]  = $status ? 'On' : 'Off';
            } else {
                $row[] = '-';
            }

            if ($device->hasPowerMeter()) {
                $row[] = sprintf('%03.1fV', $device->getPowerMeterVoltage());
                $row[] = sprintf('%03.1fV', $device->getPowerMeterPower());
                $row[] = sprintf('%03.1fV', $device->getPowerMeterEnergy());
            } else {
                $row[] = new TableCell('-', ['colspan' => 3]);
            }

            $rows[] = $row;
        }

        if (!$simpleOutput) {
            $table->setHeaders(['Identifier', 'Name', 'Temp / Offset', 'Switch', 'Voltage', 'Power', 'Energy']);
//            $table->set
        } else {
            $borderless = new TableStyle();
            $borderless
                ->setHorizontalBorderChars('')
                ->setVerticalBorderChars('')
                ->setDefaultCrossingChar('')
                ->setBorderFormat('')
            ;

            $table->setStyle($borderless);
        }

        $rightAligned = new TableStyle();
        $rightAligned->setPadType(STR_PAD_LEFT);

        $table->setColumnStyle(5, $rightAligned);
        $table->setColumnStyle(6, $rightAligned);
        $table->setColumnStyle(7, $rightAligned);

        $table->setFooterTitle(count($devices).' Devices found');
        $table->addRows($rows);
        $table->render();

        $event = $stopwatch->stop(self::$defaultName);

        if ($output->isVeryVerbose()) {
            $this->io->comment(
                sprintf(
                    'SmartHome List: Elapsed time: %.2f ms / Consumed memory: %.2f MB',
                    $event->getDuration(),
                    $event->getMemory() / (1024 ** 2)
                )
            );
        }
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
