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
 * The household comfort zone that colours temperature charts.
 *
 * Created empty: without a row ComfortZoneSettings falls back to its defaults,
 * so there is nothing to seed and the defaults live in exactly one place.
 */
final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comfort_zone table for temperature chart colouring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS comfort_zone ('
            .'id INTEGER NOT NULL PRIMARY KEY, '
            .'cold_below DOUBLE PRECISION NOT NULL, '
            .'warm_from DOUBLE PRECISION NOT NULL, '
            ."updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS comfort_zone');
    }
}
