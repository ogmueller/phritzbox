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
 * Add confirm_on / confirm_off flags to smart_device — require confirmation before
 * turning a protected device on and/or off (each direction independently).
 */
final class Version20260623160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confirm_on / confirm_off flags to smart_device';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE smart_device ADD COLUMN confirm_on BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE smart_device ADD COLUMN confirm_off BOOLEAN NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE smart_device DROP COLUMN confirm_on');
        $this->addSql('ALTER TABLE smart_device DROP COLUMN confirm_off');
    }
}
