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

namespace App\Tests\Service;

use App\Entity\AlertEvent;
use App\Entity\AlertRule;
use App\Entity\NotificationChannel;
use App\Entity\SmartDeviceData;
use App\Notification\AlertChannelInterface;
use App\Notification\AlertNotifier;
use App\Service\AlertEvaluationService;
use App\Service\SmartDeviceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AlertEvaluationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AlertEvaluationService $service;
    private NotificationChannel $channel;
    /** @var object{sent: array<int, array<string, mixed>>} */
    private object $spy;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->spy = new class implements AlertChannelInterface {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];

            public function supports(string $type): bool
            {
                return true;
            }

            public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
            {
                $this->sent[] = $context;
            }
        };

        $this->service = new AlertEvaluationService(
            $this->em->getRepository(AlertRule::class),
            $this->em,
            new AlertNotifier([$this->spy]),
            $container->get(SmartDeviceService::class),
            new NullLogger(),
        );

        $this->channel = (new NotificationChannel())
            ->setName('Test channel')->setType('webhook')->setTarget('https://example.test/hook');
        $this->em->persist($this->channel);
        $this->em->flush();
    }

    private function reading(string $sid, string $type, float $value, string $time = 'now'): void
    {
        $entry = (new SmartDeviceData())
            ->setSid($sid)
            ->setType($type)
            ->setValue($value)
            ->setTime(new \DateTimeImmutable($time));
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function persistRule(AlertRule $rule): AlertRule
    {
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    public function testThresholdAboveTriggersOnceThenCooldownSuppresses(): void
    {
        $this->reading('alert-temp', 'temperature', 25.0);
        $rule = $this->persistRule((new AlertRule())
            ->setName('Too hot')->setSid('alert-temp')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel)->setCooldownMinutes(60));

        $r1 = $this->service->evaluateAll();
        self::assertSame(1, $r1['notified']);
        self::assertCount(1, $this->spy->sent);
        self::assertSame('triggered', $this->spy->sent[0]['state']);
        self::assertSame(AlertRule::STATE_TRIGGERED, $rule->getLastState());

        // Still above, within cooldown -> no second notification.
        $r2 = $this->service->evaluateAll();
        self::assertSame(0, $r2['notified']);
        self::assertCount(1, $this->spy->sent);
    }

    public function testEdgeTriggerFiresOnceUntilConditionClears(): void
    {
        $this->reading('edge', 'temperature', 25.0);
        // cooldownMinutes defaults to 0 => alert once.
        $rule = $this->persistRule((new AlertRule())
            ->setName('Edge')->setSid('edge')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel));

        self::assertSame(1, $this->service->evaluateAll()['notified']);
        // Still above, no reminder configured -> no second notification.
        self::assertSame(0, $this->service->evaluateAll()['notified']);
        self::assertCount(1, $this->spy->sent);

        // Drops below -> resolves silently (no message) and re-arms the edge.
        $this->reading('edge', 'temperature', 20.0, '+1 second');
        self::assertSame(1, $this->service->evaluateAll()['resolved']);
        self::assertSame(AlertRule::STATE_OK, $rule->getLastState());
        self::assertCount(1, $this->spy->sent, 'resolve must not notify');

        // Breaches again -> fires once more.
        $this->reading('edge', 'temperature', 26.0, '+2 seconds');
        self::assertSame(1, $this->service->evaluateAll()['notified']);
        self::assertCount(2, $this->spy->sent); // triggered, triggered (no resolve message)
    }

    public function testReminderFiresAfterIntervalElapses(): void
    {
        $this->reading('remind', 'temperature', 25.0);
        $rule = $this->persistRule((new AlertRule())
            ->setName('Remind')->setSid('remind')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel)->setCooldownMinutes(60));

        self::assertSame(1, $this->service->evaluateAll()['notified']);

        // Pretend the last notification was long ago; still triggered -> remind again.
        $rule->setLastNotifiedAt(new \DateTimeImmutable('-2 hours'));
        $this->em->flush();
        self::assertSame(1, $this->service->evaluateAll()['notified']);
        self::assertCount(2, $this->spy->sent);
    }

    public function testNotifiesAllEnabledChannels(): void
    {
        $second = (new NotificationChannel())->setName('Second')->setType('webhook')->setTarget('https://example.test/2');
        $disabled = (new NotificationChannel())->setName('Off')->setType('webhook')->setTarget('https://example.test/3')->setEnabled(false);
        $this->em->persist($second);
        $this->em->persist($disabled);
        $this->em->flush();

        $this->reading('multi', 'temperature', 25.0);
        $this->persistRule((new AlertRule())
            ->setName('Multi')->setSid('multi')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel)->addChannel($second)->addChannel($disabled));

        self::assertSame(1, $this->service->evaluateAll()['notified']);
        // Two enabled channels each got the message; the disabled one was skipped.
        self::assertCount(2, $this->spy->sent);
    }

    public function testThresholdResolvesWhenValueDrops(): void
    {
        $this->reading('alert-temp2', 'temperature', 25.0);
        $rule = $this->persistRule((new AlertRule())
            ->setName('Too hot')->setSid('alert-temp2')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel));

        $this->service->evaluateAll();
        self::assertSame(AlertRule::STATE_TRIGGERED, $rule->getLastState());

        // New, lower reading -> resolve: state clears and it is logged, but no
        // notification is sent (only triggering sends messages).
        $this->reading('alert-temp2', 'temperature', 20.0, '+1 second');
        $r = $this->service->evaluateAll();
        self::assertSame(1, $r['resolved']);
        self::assertSame(AlertRule::STATE_OK, $rule->getLastState());
        self::assertCount(1, $this->spy->sent, 'resolve must not notify');
        self::assertSame('triggered', $this->spy->sent[0]['state']);

        // The resolution is still recorded in the activity log, with no deliveries.
        $events = $this->em->getRepository(AlertEvent::class)->findBy(['ruleId' => $rule->getId(), 'state' => 'resolved']);
        self::assertCount(1, $events);
        self::assertSame([], $events[0]->getDeliveries());
    }

    public function testPowerThresholdRespectsUnitConversion(): void
    {
        // 1500 W stored as 150000 cW; rule threshold 1000 W.
        $this->reading('alert-pow', 'power', 150000.0);
        $this->persistRule((new AlertRule())
            ->setName('High power')->setSid('alert-pow')->setType('power')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(1000.0)
            ->addChannel($this->channel));

        $r = $this->service->evaluateAll();
        self::assertSame(1, $r['triggered']);
        // Reported value is in display units (W).
        self::assertEqualsWithDelta(1500.0, $this->spy->sent[0]['value'], 0.01);
    }

    public function testComparisonWithOffset(): void
    {
        // tempA = 25, tempB = 20, rule: A > B + 2  -> 25 > 22 -> triggered
        $this->reading('dev-a', 'temperature', 25.0);
        $this->reading('dev-b', 'temperature', 20.0);
        $this->persistRule((new AlertRule())
            ->setName('A hotter than B')->setSid('dev-a')->setType('temperature')
            ->setMode(AlertRule::MODE_COMPARISON)->setOperator('gt')
            ->setCompareSid('dev-b')->setCompareType('temperature')->setCompareOffset(2.0)
            ->addChannel($this->channel));

        $r = $this->service->evaluateAll();
        self::assertSame(1, $r['triggered']);
    }

    public function testTriggerLogsEventWithDeliveryStatus(): void
    {
        $this->reading('log-temp', 'temperature', 25.0);
        $rule = $this->persistRule((new AlertRule())
            ->setName('Logged')->setSid('log-temp')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel));

        $this->service->evaluateAll();

        $events = $this->em->getRepository(AlertEvent::class)->findBy(['ruleId' => $rule->getId()]);
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame(AlertEvent::STATE_TRIGGERED, $event->getState());
        self::assertSame($rule->getName(), $event->getRuleName());
        self::assertEqualsWithDelta(25.0, $event->getValueDisplay(), 0.01);
        self::assertCount(1, $event->getDeliveries());
        self::assertTrue($event->getDeliveries()[0]['ok']);
    }

    public function testFailedChannelDeliveryIsRecorded(): void
    {
        $failing = new class implements AlertChannelInterface {
            public function supports(string $type): bool
            {
                return true;
            }

            public function send(NotificationChannel $channel, string $subject, string $body, array $context): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $service = new AlertEvaluationService(
            $this->em->getRepository(AlertRule::class),
            $this->em,
            new AlertNotifier([$failing]),
            static::getContainer()->get(SmartDeviceService::class),
            new NullLogger(),
        );

        $this->reading('fail-temp', 'temperature', 25.0);
        $rule = $this->persistRule((new AlertRule())
            ->setName('Failing')->setSid('fail-temp')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->addChannel($this->channel));

        // A failing channel must not blow up evaluation; the failure is recorded.
        $service->evaluateAll();

        $events = $this->em->getRepository(AlertEvent::class)->findBy(['ruleId' => $rule->getId()]);
        self::assertCount(1, $events);
        $delivery = $events[0]->getDeliveries()[0];
        self::assertFalse($delivery['ok']);
        self::assertSame('boom', $delivery['error']);
    }

    public function testSustainedThresholdUsesWholeWindow(): void
    {
        // Two recent readings; one is below the threshold -> NOT sustained-above.
        $this->reading('alert-sus', 'temperature', 25.0, '-2 minutes');
        $this->reading('alert-sus', 'temperature', 21.0, '-1 minute');
        $this->persistRule((new AlertRule())
            ->setName('Sustained hot')->setSid('alert-sus')->setType('temperature')
            ->setMode(AlertRule::MODE_THRESHOLD)->setOperator('gt')->setThreshold(22.0)
            ->setDurationMinutes(5)
            ->addChannel($this->channel));

        $r = $this->service->evaluateAll();
        self::assertSame(0, $r['triggered'], 'A dip below threshold within the window must prevent a sustained trigger');
    }
}
