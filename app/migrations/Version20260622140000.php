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
 * Change alert_rule -> notification_channel from many-to-one (alert_rule.channel_id)
 * to many-to-many (alert_rule_channel). Existing links are preserved.
 */
final class Version20260622140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make alert rule channels many-to-many (join table alert_rule_channel)';
    }

    public function up(Schema $schema): void
    {
        // Stash the existing 1:1 links (FK-free temp table, survives the rebuild).
        $this->addSql('CREATE TABLE _arc_tmp (rid INTEGER NOT NULL, cid INTEGER NOT NULL)');
        $this->addSql('INSERT INTO _arc_tmp (rid, cid) SELECT id, channel_id FROM alert_rule');

        // Rebuild alert_rule without channel_id (SQLite can't drop an indexed/FK column).
        $this->addSql('CREATE TABLE __temp__alert_rule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, mode VARCHAR(20) NOT NULL, sid VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, operator VARCHAR(4) NOT NULL, threshold DOUBLE PRECISION DEFAULT NULL, compare_sid VARCHAR(255) DEFAULT NULL, compare_type VARCHAR(20) DEFAULT NULL, compare_offset DOUBLE PRECISION NOT NULL, duration_minutes INTEGER NOT NULL, cooldown_minutes INTEGER NOT NULL, last_state VARCHAR(20) NOT NULL, last_triggered_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, last_notified_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
)');
        $this->addSql('INSERT INTO __temp__alert_rule (id, name, enabled, mode, sid, type, operator, threshold, compare_sid, compare_type, compare_offset, duration_minutes, cooldown_minutes, last_state, last_triggered_at, last_notified_at, created_at) SELECT id, name, enabled, mode, sid, type, operator, threshold, compare_sid, compare_type, compare_offset, duration_minutes, cooldown_minutes, last_state, last_triggered_at, last_notified_at, created_at FROM alert_rule');
        $this->addSql('DROP TABLE alert_rule');
        $this->addSql('ALTER TABLE __temp__alert_rule RENAME TO alert_rule');

        // Create the join table and restore the links.
        $this->addSql('CREATE TABLE alert_rule_channel (alert_rule_id INTEGER NOT NULL, notification_channel_id INTEGER NOT NULL, PRIMARY KEY (alert_rule_id, notification_channel_id), CONSTRAINT FK_903C1A89EA1DA493 FOREIGN KEY (alert_rule_id) REFERENCES alert_rule (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_903C1A8989870488 FOREIGN KEY (notification_channel_id) REFERENCES notification_channel (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_903C1A89EA1DA493 ON alert_rule_channel (alert_rule_id)');
        $this->addSql('CREATE INDEX IDX_903C1A8989870488 ON alert_rule_channel (notification_channel_id)');
        $this->addSql('INSERT INTO alert_rule_channel (alert_rule_id, notification_channel_id) SELECT rid, cid FROM _arc_tmp');

        $this->addSql('DROP TABLE _arc_tmp');
    }

    public function down(Schema $schema): void
    {
        // Re-add channel_id (one channel per rule, the lowest id) and drop the join table.
        $this->addSql('CREATE TABLE _arc_tmp (rid INTEGER NOT NULL, cid INTEGER NOT NULL)');
        $this->addSql('INSERT INTO _arc_tmp (rid, cid) SELECT alert_rule_id, MIN(notification_channel_id) FROM alert_rule_channel GROUP BY alert_rule_id');
        $this->addSql('DROP TABLE alert_rule_channel');

        $this->addSql('CREATE TABLE __temp__alert_rule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, mode VARCHAR(20) NOT NULL, sid VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, operator VARCHAR(4) NOT NULL, threshold DOUBLE PRECISION DEFAULT NULL, compare_sid VARCHAR(255) DEFAULT NULL, compare_type VARCHAR(20) DEFAULT NULL, compare_offset DOUBLE PRECISION NOT NULL, duration_minutes INTEGER NOT NULL, cooldown_minutes INTEGER NOT NULL, last_state VARCHAR(20) NOT NULL, last_triggered_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, last_notified_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, channel_id INTEGER NOT NULL DEFAULT 0)');
        $this->addSql('INSERT INTO __temp__alert_rule (id, name, enabled, mode, sid, type, operator, threshold, compare_sid, compare_type, compare_offset, duration_minutes, cooldown_minutes, last_state, last_triggered_at, last_notified_at, created_at, channel_id) SELECT a.id, a.name, a.enabled, a.mode, a.sid, a.type, a.operator, a.threshold, a.compare_sid, a.compare_type, a.compare_offset, a.duration_minutes, a.cooldown_minutes, a.last_state, a.last_triggered_at, a.last_notified_at, a.created_at, COALESCE(t.cid, 0) FROM alert_rule a LEFT JOIN _arc_tmp t ON t.rid = a.id');
        $this->addSql('DROP TABLE alert_rule');
        $this->addSql('ALTER TABLE __temp__alert_rule RENAME TO alert_rule');
        $this->addSql('CREATE INDEX IDX_C9687E4872F5A1AA ON alert_rule (channel_id)');
        $this->addSql('DROP TABLE _arc_tmp');
    }
}
