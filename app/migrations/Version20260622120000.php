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

final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification_channel and alert_rule tables for the alerting system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_channel (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, target VARCHAR(1024) NOT NULL, secret VARCHAR(255) DEFAULT NULL, enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
)');
        $this->addSql('CREATE TABLE alert_rule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, mode VARCHAR(20) NOT NULL, sid VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, operator VARCHAR(4) NOT NULL, threshold DOUBLE PRECISION DEFAULT NULL, compare_sid VARCHAR(255) DEFAULT NULL, compare_type VARCHAR(20) DEFAULT NULL, compare_offset DOUBLE PRECISION NOT NULL, duration_minutes INTEGER NOT NULL, cooldown_minutes INTEGER NOT NULL, last_state VARCHAR(20) NOT NULL, last_triggered_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, last_notified_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, channel_id INTEGER NOT NULL, CONSTRAINT FK_C9687E4872F5A1AA FOREIGN KEY (channel_id) REFERENCES notification_channel (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C9687E4872F5A1AA ON alert_rule (channel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert_rule');
        $this->addSql('DROP TABLE notification_channel');
    }
}
