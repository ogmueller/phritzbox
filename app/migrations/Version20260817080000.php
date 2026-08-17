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

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pre-aggregated readings, so long reports stop re-scanning millions of raw
 * rows and old raw rows eventually become removable.
 *
 * One table for every resolution, keyed by `grid` (the bucket width in
 * seconds): 900 for the quarter-hour tier, 86400 for the daily one. Adding a
 * finer tier later is a new grid value, not a new table and not a migration.
 *
 * This migration creates the table EMPTY on purpose. It runs inside the
 * container entrypoint on every boot, where a statement that touched 53M rows
 * would blow through the healthcheck grace period and crash-loop the deploy.
 * Populating it is `smart:rollup:backfill`, which is resumable and interruptible.
 */
final class Version20260817080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add smart_device_data_rollup for pre-aggregated readings';
    }

    public function up(Schema $schema): void
    {
        // time_min carries MIN(time) of the source rows so a rollup-served point
        // has exactly the timestamp the raw-served one had — that is what keeps
        // report output identical whichever tier answers the query.
        // sum_value + sample_count let a coarser tier be derived exactly from a
        // finer one: SUM(sum)/SUM(count) is the same number as AVG over raw.
        $this->addSql('CREATE TABLE IF NOT EXISTS smart_device_data_rollup (
            sid VARCHAR(255) NOT NULL,
            type VARCHAR(255) NOT NULL,
            grid INTEGER NOT NULL,
            bucket DATETIME NOT NULL --(DC2Type:datetime_immutable)
, time_min DATETIME NOT NULL --(DC2Type:datetime_immutable)
, avg_value DOUBLE PRECISION NOT NULL,
            min_value DOUBLE PRECISION NOT NULL,
            max_value DOUBLE PRECISION NOT NULL,
            sum_value DOUBLE PRECISION NOT NULL,
            sample_count INTEGER NOT NULL,
            PRIMARY KEY(sid, type, grid, bucket)
        )');

        // The primary key already serves every read (sid + type + grid + bucket
        // range) and every retention delete, so no secondary index is needed.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS smart_device_data_rollup');
    }
}
