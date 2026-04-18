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

final class Version20260411024338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user table for web frontend authentication';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(255) NOT NULL, roles CLOB NOT NULL --(DC2Type:json)
, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_8D93D649F85E0677 ON "user" (username)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_8D93D649E7927C74 ON "user" (email)');

        // Default admin user — change the password after first login
        $hash = password_hash('admin', \PASSWORD_BCRYPT, ['cost' => 12]);
        $this->addSql(
            'INSERT OR IGNORE INTO "user" (username, email, roles, password, created_at) VALUES (?, ?, ?, ?, ?)',
            ['admin', 'admin@localhost', '["ROLE_ADMIN","ROLE_USER"]', $hash, (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE "user"');
    }
}
