<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250502120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accounts and transfers; monetary amounts stored as unsigned BIGINT minor units.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, public_id CHAR(36) NOT NULL, balance_minor BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_7D3656A4B5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE `transfer` (id INT AUTO_INCREMENT NOT NULL, public_id CHAR(36) NOT NULL, from_account_id INT NOT NULL, to_account_id INT NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4034A3C0F396750 (from_account_id), INDEX IDX_4034A3C0B03A8386 (to_account_id), UNIQUE INDEX UNIQ_4034A3C0B5B48B91 (public_id), UNIQUE INDEX UNIQ_4034A3C0EA588816 (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE `transfer` ADD CONSTRAINT FK_4034A3C0F396750 FOREIGN KEY (from_account_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE `transfer` ADD CONSTRAINT FK_4034A3C0B03A8386 FOREIGN KEY (to_account_id) REFERENCES account (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `transfer` DROP FOREIGN KEY FK_4034A3C0F396750');
        $this->addSql('ALTER TABLE `transfer` DROP FOREIGN KEY FK_4034A3C0B03A8386');
        $this->addSql('DROP TABLE `transfer`');
        $this->addSql('DROP TABLE account');
    }
}
