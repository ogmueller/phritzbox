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

final class Version20260416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add smart_device table to cache device metadata locally';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS smart_device (ain VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL DEFAULT \'\', manufacturer VARCHAR(255) NOT NULL DEFAULT \'\', product_name VARCHAR(255) NOT NULL DEFAULT \'\', firmware_version VARCHAR(50) NOT NULL DEFAULT \'\', function_bit_mask INTEGER NOT NULL DEFAULT 0, first_seen_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, last_seen_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, PRIMARY KEY(ain))');

        // Backfill stub rows for every device already known from measurements
        $this->addSql("INSERT OR IGNORE INTO smart_device (ain, name, manufacturer, product_name, firmware_version, function_bit_mask, first_seen_at, last_seen_at) SELECT sid, '', '', '', '', 0, MIN(time), MAX(time) FROM smart_device_data GROUP BY sid");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS smart_device');
    }
}
