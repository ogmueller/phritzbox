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
 * The electricity tariff, so collected energy can be expressed as money.
 *
 * A single row, but typed columns rather than another key/value pair: `app_state`
 * exists for operational bookkeeping (watermarks, locks) and stores untyped
 * VARCHAR(255), whereas this is operator configuration with real numeric bounds.
 * It is also the shape that grows: the moment a price needs a validity range
 * ("from 1 January", day/night rates) this table takes a `valid_from` column and
 * becomes a history without a redesign, which a key/value store cannot.
 *
 * Created EMPTY on purpose. "No tariff configured" is a real state that must be
 * distinguishable from "the price is zero" — a user with their own solar may
 * legitimately set 0.00 — so seeding a default row would make every cost render
 * as 0.00 instead of as "not configured".
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tariff table for energy cost calculation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE IF NOT EXISTS tariff ('
            .'id INTEGER NOT NULL PRIMARY KEY, '
            // Nullable: NULL means "not configured", distinct from 0.0.
            .'price_per_kwh DOUBLE PRECISION DEFAULT NULL, '
            .'standing_charge_month DOUBLE PRECISION NOT NULL DEFAULT 0, '
            ."currency VARCHAR(3) NOT NULL DEFAULT 'EUR', "
            ."updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)\n)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tariff');
    }
}
