<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Scope idempotency keys per authenticated subject (JWT sub / api-key client / anonymous).
 */
final class Version20260301120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotency_owner; unique (owner, idempotency_key) instead of global idempotency_key.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `transfer` ADD idempotency_owner VARCHAR(128) NOT NULL DEFAULT \'\'');
        $this->addSql('DROP INDEX UNIQ_4034A3C0EA588816 ON `transfer`');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TRANSFER_IDEMP_OWNER_KEY ON `transfer` (idempotency_owner, idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_TRANSFER_IDEMP_OWNER_KEY ON `transfer`');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4034A3C0EA588816 ON `transfer` (idempotency_key)');
        $this->addSql('ALTER TABLE `transfer` DROP idempotency_owner');
    }
}
