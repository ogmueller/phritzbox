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

use App\Service\StatsRange;
use PHPUnit\Framework\TestCase;

class StatsRangeTest extends TestCase
{
    public function testEmptyValueFallsBackToTheGivenDefault(): void
    {
        $from = StatsRange::parseBound('', '-24 hours', endOfDay: false);
        $to = StatsRange::parseBound('', 'now', endOfDay: true);

        self::assertLessThan($to->getTimestamp(), $from->getTimestamp());
        self::assertEqualsWithDelta(86400, $to->getTimestamp() - $from->getTimestamp(), 5);
    }

    public function testBareDateEndBoundIsWidenedToTheDaysLastSecond(): void
    {
        // A bare date carries no time, so an end bound of "2026-06-01" must mean
        // the whole day, not its first instant.
        $to = StatsRange::parseBound('2026-06-01', 'now', endOfDay: true);

        self::assertSame('2026-06-01 23:59:59', $to->format('Y-m-d H:i:s'));
    }

    public function testBareDateStartBoundIsNotWidened(): void
    {
        $from = StatsRange::parseBound('2026-06-01', '-24 hours', endOfDay: false);

        self::assertSame('2026-06-01 00:00:00', $from->format('Y-m-d H:i:s'));
    }

    public function testAnInstantIsNeverWidenedEvenOnAnEndBound(): void
    {
        // The widening applies only when the value really is a bare date —
        // otherwise a rolling window ending "now" would jump to end of day.
        $to = StatsRange::parseBound('2026-06-01T14:32:00+00:00', 'now', endOfDay: true);

        self::assertSame('14:32:00', $to->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s'));
    }

    public function testAnOffsetBearingInstantIsConvertedToTheServerTimezone(): void
    {
        // Readings are stored formatted in the server timezone; without the
        // conversion an incoming +02:00 instant would silently shift the window.
        $parsed = StatsRange::parseBound('2026-06-01T12:00:00+02:00', 'now', endOfDay: false);

        self::assertSame(date_default_timezone_get(), $parsed->getTimezone()->getName());
        self::assertSame(
            (new \DateTimeImmutable('2026-06-01T12:00:00+02:00'))->getTimestamp(),
            $parsed->getTimestamp(),
            'the instant itself must not move',
        );
    }

    public function testUnparseableValueThrows(): void
    {
        $this->expectException(\Exception::class);

        StatsRange::parseBound('not-a-date', 'now', endOfDay: false);
    }

    public function testEnergyStartIsWidenedToMidnight(): void
    {
        // Energy is stamped at midnight, so a mid-day start could only clip a
        // whole day off the left edge.
        $from = new \DateTimeImmutable('2026-06-01 14:32:07');

        self::assertSame(
            '2026-06-01 00:00:00',
            StatsRange::widenForEnergy($from, 'energy')->format('Y-m-d H:i:s'),
        );
    }

    public function testOtherMetricsAreNotWidened(): void
    {
        $from = new \DateTimeImmutable('2026-06-01 14:32:07');

        foreach (['power', 'temperature', 'voltage', 'battery', 'presence'] as $type) {
            self::assertSame(
                '2026-06-01 14:32:07',
                StatsRange::widenForEnergy($from, $type)->format('Y-m-d H:i:s'),
                $type,
            );
        }
    }

    public function testTheAllTypesQueryIsNotWidened(): void
    {
        // Widening the all-types query would drag in extra temperature and
        // power rows that the caller did not ask for.
        $from = new \DateTimeImmutable('2026-06-01 14:32:07');

        self::assertSame(
            '2026-06-01 14:32:07',
            StatsRange::widenForEnergy($from, '')->format('Y-m-d H:i:s'),
        );
    }
}
