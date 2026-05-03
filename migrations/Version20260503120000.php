<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store idempotency_owner as SHA-256 hex so long JWT subs cannot collide on prefix truncation.
 */
final class Version20260503120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hash transfer.idempotency_owner values (MySQL SHA256, matches PHP hash("sha256", ...)).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE transfer SET idempotency_owner = SHA2(idempotency_owner, 256) WHERE idempotency_owner <> ''",
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Cannot reverse idempotency_owner hashing without a separate mapping table.',
        );
    }
}
