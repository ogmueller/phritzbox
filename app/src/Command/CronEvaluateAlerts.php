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

use App\Service\AlertEvaluationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Evaluate all enabled alert rules and send notifications.
 *
 * Intended to run shortly after each data collection (see cronado labels).
 */
#[AsCommand(name: 'cron:smart:alerts', description: 'Evaluate alert rules and send notifications')]
final class CronEvaluateAlerts extends Command
{
    public function __construct(private readonly AlertEvaluationService $alerts)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->alerts->evaluateAll();

        $io->writeln(\sprintf(
            'Evaluated %d rule(s): %d triggered, %d notified, %d resolved',
            $result['rules'],
            $result['triggered'],
            $result['notified'],
            $result['resolved'],
        ));

        return Command::SUCCESS;
    }
}
