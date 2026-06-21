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

final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idx_sid_type_time index on smart_device_data (sid, type, time)';
    }

    public function up(Schema $schema): void
    {
        // The entity declares this index, but it was never created in existing
        // databases. Without it, every report query full-scans the whole table.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sid_type_time ON smart_device_data (sid, type, time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_sid_type_time');
    }
}
