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

namespace App\Service;

use App\Entity\AlertRule;
use App\Notification\AlertNotifier;
use App\Repository\AlertRuleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates alert rules against the stored readings and dispatches
 * notifications on state transitions (edge-triggered, with a cooldown).
 */
final class AlertEvaluationService
{
    private const OP_LABELS = ['gt' => '>', 'lt' => '<', 'gte' => '>=', 'lte' => '<='];

    public function __construct(
        private readonly AlertRuleRepository $rules,
        private readonly EntityManagerInterface $entityManager,
        private readonly AlertNotifier $notifier,
        private readonly SmartDeviceService $devices,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{rules: int, triggered: int, notified: int, resolved: int}
     */
    public function evaluateAll(): array
    {
        $now = new \DateTimeImmutable();
        $rules = $this->rules->findEnabled();
        $triggered = 0;
        $notified = 0;
        $resolved = 0;

        foreach ($rules as $rule) {
            try {
                $eval = $this->evaluateRule($rule, $now);
                if ($eval === null) {
                    continue; // not enough data — leave state unchanged
                }

                if ($eval['triggered']) {
                    ++$triggered;
                    if ($this->handleTriggered($rule, $eval, $now)) {
                        ++$notified;
                    }
                } elseif ($rule->getLastState() === AlertRule::STATE_TRIGGERED) {
                    $rule->setLastState(AlertRule::STATE_OK);
                    $this->dispatch($rule, false, $eval, $now);
                    $rule->setLastNotifiedAt($now);
                    ++$resolved;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Alert rule evaluation failed', [
                    'rule' => $rule->getId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->entityManager->flush();

        return ['rules' => \count($rules), 'triggered' => $triggered, 'notified' => $notified, 'resolved' => $resolved];
    }

    /**
     * Send a one-off test notification through the rule's channel.
     */
    public function sendTest(AlertRule $rule): void
    {
        if ($rule->getChannels()->isEmpty()) {
            throw new \RuntimeException('Alert rule has no notification channels');
        }

        $subject = \sprintf('[Phritzbox] Test: %s', $rule->getName());
        $errors = [];

        foreach ($rule->getChannels() as $channel) {
            $body = \sprintf(
                "This is a test notification for alert rule \"%s\".\nChannel: %s (%s)\nTarget: %s",
                $rule->getName(),
                $channel->getName(),
                $channel->getType(),
                $channel->getTarget(),
            );
            try {
                $this->notifier->notify($channel, $subject, $body, ['rule' => $rule->getName(), 'state' => 'test', 'ruleId' => $rule->getId()]);
            } catch (\Throwable $e) {
                $errors[] = $channel->getName().': '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }
    }

    /**
     * @param array{triggered: bool, valueDisplay: float|null, compareDisplay?: float|null} $eval
     */
    private function handleTriggered(AlertRule $rule, array $eval, \DateTimeImmutable $now): bool
    {
        $shouldNotify = false;

        if ($rule->getLastState() !== AlertRule::STATE_TRIGGERED) {
            // Edge: the condition just became met — always notify.
            $rule->setLastState(AlertRule::STATE_TRIGGERED);
            $rule->setLastTriggeredAt($now);
            $shouldNotify = true;
        } elseif ($rule->getCooldownMinutes() > 0) {
            // Already met: only re-notify when an opt-in reminder interval is set
            // (cooldownMinutes = 0 means "alert once until it clears").
            $last = $rule->getLastNotifiedAt();
            $cooldownAgo = $now->modify('-'.$rule->getCooldownMinutes().' minutes');
            if ($last === null || $last <= $cooldownAgo) {
                $shouldNotify = true;
            }
        }

        if ($shouldNotify) {
            $this->dispatch($rule, true, $eval, $now);
            $rule->setLastNotifiedAt($now);
        }

        return $shouldNotify;
    }

    /**
     * @return array{triggered: bool, valueDisplay: float|null, compareDisplay?: float|null}|null
     */
    private function evaluateRule(AlertRule $rule, \DateTimeImmutable $now): ?array
    {
        $conn = $this->entityManager->getConnection();
        $type = $rule->getType();

        if ($rule->getMode() === AlertRule::MODE_COMPARISON) {
            $a = $this->latestValue($conn, $rule->getSid(), $type);
            $b = $this->latestValue($conn, (string) $rule->getCompareSid(), (string) $rule->getCompareType());
            if ($a === null || $b === null) {
                return null;
            }
            $offsetStored = MetricUnits::toStored($rule->getCompareType() ?? $type, $rule->getCompareOffset());

            return [
                'triggered' => $this->compare($rule->getOperator(), $a, $b + $offsetStored),
                'valueDisplay' => MetricUnits::toDisplay($type, $a),
                'compareDisplay' => MetricUnits::toDisplay($rule->getCompareType() ?? $type, $b),
            ];
        }

        $thresholdStored = MetricUnits::toStored($type, (float) $rule->getThreshold());

        if ($rule->getDurationMinutes() > 0) {
            $since = $now->modify('-'.$rule->getDurationMinutes().' minutes')->format('Y-m-d H:i:s');
            $row = $conn->fetchAssociative(
                'SELECT MIN(value) AS mn, MAX(value) AS mx, COUNT(*) AS c'
                .' FROM smart_device_data WHERE sid = :sid AND type = :type AND time >= :since',
                ['sid' => $rule->getSid(), 'type' => $type, 'since' => $since],
            );
            if (!$row || (int) $row['c'] === 0) {
                return null;
            }
            $op = $rule->getOperator();
            // Sustained: every sample in the window must satisfy the operator.
            $triggered = match ($op) {
                'gt' => (float) $row['mn'] > $thresholdStored,
                'gte' => (float) $row['mn'] >= $thresholdStored,
                'lt' => (float) $row['mx'] < $thresholdStored,
                'lte' => (float) $row['mx'] <= $thresholdStored,
                default => false,
            };
            // Report the worst-case sample relative to the threshold.
            $reportStored = \in_array($op, ['gt', 'gte'], true) ? (float) $row['mn'] : (float) $row['mx'];

            return ['triggered' => $triggered, 'valueDisplay' => MetricUnits::toDisplay($type, $reportStored)];
        }

        $v = $this->latestValue($conn, $rule->getSid(), $type);
        if ($v === null) {
            return null;
        }

        return [
            'triggered' => $this->compare($rule->getOperator(), $v, $thresholdStored),
            'valueDisplay' => MetricUnits::toDisplay($type, $v),
        ];
    }

    private function latestValue(Connection $conn, string $sid, string $type): ?float
    {
        $row = $conn->fetchAssociative(
            'SELECT value FROM smart_device_data WHERE sid = :sid AND type = :type ORDER BY time DESC LIMIT 1',
            ['sid' => $sid, 'type' => $type],
        );

        return $row === false ? null : (float) $row['value'];
    }

    private function compare(string $op, float $a, float $b): bool
    {
        return match ($op) {
            'gt' => $a > $b,
            'gte' => $a >= $b,
            'lt' => $a < $b,
            'lte' => $a <= $b,
            default => false,
        };
    }

    /**
     * @param array{triggered: bool, valueDisplay: float|null, compareDisplay?: float|null} $eval
     */
    private function dispatch(AlertRule $rule, bool $triggered, array $eval, \DateTimeImmutable $now): void
    {
        $type = $rule->getType();
        $unit = MetricUnits::unit($type);
        $op = self::OP_LABELS[$rule->getOperator()] ?? $rule->getOperator();
        $state = $triggered ? 'TRIGGERED' : 'RESOLVED';
        $deviceName = $this->deviceName($rule->getSid());
        $subject = \sprintf('[Phritzbox] %s — %s', $rule->getName(), $state);

        if ($rule->getMode() === AlertRule::MODE_COMPARISON) {
            $compareName = $this->deviceName((string) $rule->getCompareSid());
            $body = \sprintf(
                "Alert \"%s\" %s.\n\n%s %s = %s %s\n%s %s = %s %s (offset %+.2f)\nCondition: A %s B + offset\nTime: %s",
                $rule->getName(),
                mb_strtolower($state),
                $deviceName,
                $type,
                $this->fmt($eval['valueDisplay'] ?? null),
                $unit,
                $compareName,
                (string) $rule->getCompareType(),
                $this->fmt($eval['compareDisplay'] ?? null),
                MetricUnits::unit($rule->getCompareType() ?? $type),
                $rule->getCompareOffset(),
                $op,
                $now->format('Y-m-d H:i:s'),
            );
        } else {
            $duration = $rule->getDurationMinutes() > 0 ? \sprintf(' for %d min', $rule->getDurationMinutes()) : '';
            $body = \sprintf(
                "Alert \"%s\" %s.\n\n%s %s = %s %s\nCondition: %s %s %s%s\nTime: %s",
                $rule->getName(),
                mb_strtolower($state),
                $deviceName,
                $type,
                $this->fmt($eval['valueDisplay'] ?? null),
                $unit,
                $type,
                $op,
                $this->fmt($rule->getThreshold()),
                $unit.$duration,
                $now->format('Y-m-d H:i:s'),
            );
        }

        $context = [
            'rule' => $rule->getName(),
            'state' => $triggered ? 'triggered' : 'resolved',
            'ruleId' => $rule->getId(),
            'device' => $rule->getSid(),
            'deviceName' => $deviceName,
            'type' => $type,
            'value' => $eval['valueDisplay'] ?? null,
            'unit' => $unit,
            'time' => $now->format(\DateTimeInterface::ATOM),
        ];

        // Notify every enabled channel; one failing channel must not block the rest.
        foreach ($rule->getChannels() as $channel) {
            if (!$channel->isEnabled()) {
                continue;
            }
            try {
                $this->notifier->notify($channel, $subject, $body, $context);
            } catch (\Throwable $e) {
                $this->logger->error('Alert notification failed', [
                    'rule' => $rule->getId(),
                    'channel' => $channel->getId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function deviceName(string $ain): string
    {
        return $this->devices->findByAin($ain)?->getName() ?: $ain;
    }

    private function fmt(?float $value): string
    {
        return $value === null ? 'n/a' : mb_rtrim(mb_rtrim(\sprintf('%.2f', $value), '0'), '.');
    }
}
