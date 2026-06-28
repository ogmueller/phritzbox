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
 * Small key/value table for operational state. Currently holds
 * `last_collection_at`, written at the end of each data collection so the UI can
 * warn when live data has gone stale (e.g. the host slept and cron was skipped).
 */
final class Version20260628120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_state key/value table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE app_state ('
            .'name VARCHAR(64) NOT NULL PRIMARY KEY, '
            .'value VARCHAR(255) DEFAULT NULL, '
            ."updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_state');
    }
}
