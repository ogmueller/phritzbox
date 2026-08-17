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

/**
 * How a requested time window is interpreted.
 *
 * Extracted so every caller resolves "this window" identically. The chart, the
 * export and the cost figure all answer questions about the same range, and if
 * any of them parsed its bounds even slightly differently the numbers would
 * disagree with each other on screen — a cost total that contradicts the bars
 * directly above it is worse than no cost total at all.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
final class StatsRange
{
    /**
     * Parse a `from`/`to` query bound.
     *
     * Accepts an offset-bearing ISO 8601 instant — what the UI sends, so a
     * rolling window means the same moment regardless of where browser and
     * server sit — or a bare `Y-m-d`, kept for older clients and hand-made API
     * calls. A bare date carries no time, so an end bound is widened to that
     * day's last second (the long-standing behaviour, now applied only when the
     * value really is a bare date).
     *
     * The result is converted to the server timezone because readings are
     * stored formatted in it; without this an incoming +02:00 instant would
     * format two hours off and silently shift the window.
     *
     * @throws \Exception when the value cannot be parsed
     */
    public static function parseBound(string $raw, string $fallback, bool $endOfDay): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(date_default_timezone_get());

        if ($raw === '') {
            return new \DateTimeImmutable($fallback, $tz);
        }

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw .= ' 23:59:59';
        }

        return (new \DateTimeImmutable($raw))->setTimezone($tz);
    }

    /**
     * Widen a start bound to midnight for energy queries.
     *
     * The box reports energy once per day (grid 86400) and stamps each value at
     * midnight, so a window that starts mid-day can only ever clip a whole day
     * off the left edge — never refine one.
     *
     * Applies only to an explicit energy request: on the all-types query
     * (`$type === ''`) widening would drag in extra temperature and power rows.
     */
    public static function widenForEnergy(\DateTimeImmutable $from, string $type): \DateTimeImmutable
    {
        return $type === MetricUnits::TYPE_ENERGY ? $from->setTime(0, 0) : $from;
    }
}
