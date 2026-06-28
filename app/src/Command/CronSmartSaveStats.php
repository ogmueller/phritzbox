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
use App\Service\SmartStatsCollectionService;
use Doctrine\ORM\EntityManagerInterface;
use noximo\PHPColoredAsciiLinechart\Linechart;
use noximo\PHPColoredAsciiLinechart\Settings;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to store all stats from all available devices.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'cron:smart:savestats', description: 'Collect and store all stats from all available devices')]
class CronSmartSaveStats extends Smart
{
    private SmartStatsCollectionService $smartStatsCollectionService;

    public function __construct(
        AhaApi $ahaApi,
        EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.app')]
        CacheItemPoolInterface $cache,
        SmartStatsCollectionService $smartStatsCollectionService,
    ) {
        parent::__construct($ahaApi, $entityManager, $cache);
        $this->smartStatsCollectionService = $smartStatsCollectionService;
    }

    protected function configure(): void
    {
        $this->setHelp($this->getCommandHelp());
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch,
    ): int {
        $stopwatch->start('collect');
        $result = $this->smartStatsCollectionService->collectAll();
        $collectEvent = $stopwatch->stop('collect');

        if ($output->isVerbose()) {
            foreach ($result['perDevice'] as $ain => $info) {
                $this->io->writeln(\sprintf(
                    'Device %s [%s] — %d new rows',
                    $info['name'],
                    $ain,
                    $info['rows'],
                ));
            }
        }

        $this->io->writeln(\sprintf(
            'Collected %d new rows from %d devices in %.0f ms',
            $result['rows'],
            $result['devices'],
            $collectEvent->getDuration(),
        ));

        return 0;
    }

    /**
     * @param array<int, float> $values
     *
     * @return array{0: Linechart, 1: array<int, float>}
     */
    protected function createChart(array $values, int $factor, Settings $settings): array
    {
        $terminalWidth = getenv('COLUMNS');
        $chartWidth = $terminalWidth - $settings->getOffset() - 6;
        $maxXScaleHeight = 20;

        if (\count($values) > $chartWidth) {
            // get most current values printable at given console width
            $values = \array_slice($values, 0, $chartWidth);
        }

        if ($factor !== 0) {
            // convert values to best readable unit
            $values = array_map(
                static function ($value) use ($factor) {
                    return $value / $factor;
                },
                $values
            );
        }

        $chart = new Linechart();
        $height = ceil(max($values)) - floor(min($values));
        $settings->setHeight(max(1, min($maxXScaleHeight, $height)));
        $chart->setSettings($settings);

        return [$chart, $values];
    }

    /**
     * Convert seconds into e.g. 5days 3h 40sec.
     *
     * @param int $value Seconds
     */
    protected function humanReadableTime(int $value): string
    {
        $prefixList = [
            'yr' => 32140800,
            'mo' => 2678400,
            'wk' => 604800,
            'd' => 86400,
            'hr' => 3600,
            'min' => 60,
            'sec' => 1,
        ];

        $ret = [];
        foreach ($prefixList as $prefix => $factor) {
            $mod = $value % $factor;
            $value /= $factor;
            if ($value >= 1) {
                $ret[] = floor($value).$prefix;
            }
            $value = $mod;
        }

        return implode(' ', $ret);
    }

    protected function timeRange(int $resolution, int $count): string
    {
        $seconds = $count * $resolution;
        if ($seconds < 86400) {
            $format = 'H:i:s';
        } elseif ($seconds < 604800) {
            $format = 'D, H:i:s';
        } elseif ($seconds < 3024000) {
            $format = 'd M';
        } else {
            $format = 'M Y';
        }

        $time = (-1 * $seconds).' seconds';

        $latest = (new \DateTimeImmutable('NOW'))->format($format);
        $oldest = (new \DateTimeImmutable($time))->format($format);

        $unit = $this->humanReadableTime($resolution * $count);

        return $unit.' ('.$oldest.' - '.$latest.')';
    }

    /**
     * The command help is usually included in the configure() method, but when
     * it's too long, it's better to define a separate method to maintain the
     * code readability.
     */
    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command shows basic information of a SmartHome device:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output show graphs depending on the features of the device (e.g. temperature, power, energy, ...).
Each graph has a headline with the type of information and its unit as well as the time range and interval it represents.
The line from left to right is from older to newer values.

You can also use the <comment>-s</comment> option to get a simplified output. In this case the information will be shown
as comma seperated values (CSV):

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

The CSVs will always start with the following information: name, unit , number of values, time interval.
After that all the values will be appended in a descending time order (latest first, oldest last).

HELP;
    }
}
