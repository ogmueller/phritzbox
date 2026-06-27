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
 * De-duplicate smart_device_data and enforce one reading per (sid, type, time).
 *
 * Historically, overlapping collection runs (cron racing a manual pull) could
 * insert the same grid point twice, which doubled chart tooltips. This removes
 * the existing duplicates (keeping the lowest data_id per group) and replaces
 * the non-unique index with a UNIQUE one so they can't reappear.
 *
 * NOTE: deleted duplicate rows are not restorable on rollback.
 */
final class Version20260627100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'De-duplicate smart_device_data and add UNIQUE(sid, type, time)';
    }

    public function up(Schema $schema): void
    {
        // Delete every duplicate row, keeping the lowest data_id per group.
        // The join only touches groups that actually have duplicates, so this
        // reads a handful of rows via idx_sid_type_time rather than the whole table.
        $this->addSql(
            'DELETE FROM smart_device_data WHERE data_id IN ('
            .' SELECT s.data_id FROM smart_device_data s'
            .' JOIN ('
            .'   SELECT sid, type, time, MIN(data_id) AS keep_id'
            .'   FROM smart_device_data GROUP BY sid, type, time HAVING COUNT(*) > 1'
            .' ) d ON s.sid = d.sid AND s.type = d.type AND s.time = d.time'
            .' WHERE s.data_id <> d.keep_id'
            .')'
        );

        $this->addSql('DROP INDEX idx_sid_type_time');
        $this->addSql('CREATE UNIQUE INDEX uniq_sid_type_time ON smart_device_data (sid, type, time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_sid_type_time');
        $this->addSql('CREATE INDEX idx_sid_type_time ON smart_device_data (sid, type, time)');
    }
}
