<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operational index for reconciliation / range scans by time under load (admin or async jobs).
 */
final class Version20260202120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index transfer.created_at for time-ordered lookups.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_transfer_created_at ON `transfer` (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_transfer_created_at ON `transfer`');
    }
}
