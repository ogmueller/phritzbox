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
 * Add alert_event — an append-only log of alert rule firings/resolutions with
 * per-channel notification delivery status.
 */
final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add alert_event log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE alert_event ('
            .'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            .'rule_id INTEGER DEFAULT NULL, '
            .'rule_name VARCHAR(255) NOT NULL, '
            .'state VARCHAR(20) NOT NULL, '
            .'type VARCHAR(20) NOT NULL, '
            .'value_display DOUBLE PRECISION DEFAULT NULL, '
            .'compare_display DOUBLE PRECISION DEFAULT NULL, '
            ."deliveries CLOB NOT NULL --(DC2Type:json)\n, "
            ."created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n)"
        );
        $this->addSql('CREATE INDEX idx_alert_event_created ON alert_event (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert_event');
    }
}
