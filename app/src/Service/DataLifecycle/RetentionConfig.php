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

namespace App\Service\DataLifecycle;

use App\Service\MetricUnits;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How long each tier is kept.
 *
 * Every value defaults to 0, meaning "keep forever". Nothing is deleted until
 * an operator sets a limit deliberately — an image update must never start
 * discarding someone's history on its own.
 *
 * The read path consults these too: once raw rows stop existing beyond a
 * cutoff, a narrow window further back has to be answered from a coarser tier
 * rather than returning an empty chart.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
final readonly class RetentionConfig
{
    public function __construct(
        #[Autowire('%env(int:APP_RETENTION_RAW_DAYS)%')]
        public int $rawDays = 0,
        #[Autowire('%env(int:APP_RETENTION_QUARTER_DAYS)%')]
        public int $quarterDays = 0,
        #[Autowire('%env(int:APP_RETENTION_DAILY_DAYS)%')]
        public int $dailyDays = 0,
        /**
         * Per-metric overrides of rawDays, as `type=days` pairs.
         *
         * Metrics are wildly unequal: voltage and power are ~99% of the stored
         * rows between them, and mains voltage barely moves, so keeping it at
         * 24-second resolution for as long as temperature makes little sense.
         * One global number would force the most valuable metric and the least
         * valuable one onto the same policy.
         */
        #[Autowire('%env(string:APP_RETENTION_RAW_OVERRIDES)%')]
        private string $rawOverrides = '',
    ) {
    }

    /** Retention for one metric: its override if set, otherwise the global value. */
    public function rawDaysFor(string $type): int
    {
        return $this->parsedOverrides()[$type] ?? $this->rawDays;
    }

    /**
     * Override keys that name no real metric.
     *
     * An override for a metric that does not exist is never consulted, so a typo
     * like "voltag=14" silently does nothing at all. Surfacing them lets the
     * prune command say so rather than leaving someone to wonder why their
     * setting had no effect.
     *
     * @return list<string>
     */
    public function unknownOverrides(): array
    {
        return array_values(array_filter(
            array_keys($this->parsedOverrides()),
            static fn (string $type): bool => !MetricUnits::isValidType($type),
        ));
    }

    /** Oldest instant still expected to hold raw rows, or null when nothing is pruned. */
    public function rawCutoff(\DateTimeImmutable $now, ?string $type = null): ?\DateTimeImmutable
    {
        $days = $type === null ? $this->rawDays : $this->rawDaysFor($type);

        return $days > 0 ? $now->modify(\sprintf('-%d days', $days)) : null;
    }

    /**
     * @return array<string, int>
     */
    private function parsedOverrides(): array
    {
        $parsed = [];
        foreach (explode(',', $this->rawOverrides) as $pair) {
            $pair = mb_trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$type, $days] = explode('=', $pair, 2);
            $type = mb_trim($type);
            // A malformed or negative entry is ignored rather than silently
            // turning into "prune everything".
            if ($type !== '' && (int) $days > 0) {
                $parsed[$type] = (int) $days;
            }
        }

        return $parsed;
    }

    public function quarterCutoff(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->quarterDays > 0 ? $now->modify(\sprintf('-%d days', $this->quarterDays)) : null;
    }
}
