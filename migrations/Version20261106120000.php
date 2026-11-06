<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Regroupement des variantes de commerçant : operation.merchant_group (clé représentative du
 * groupe, NULL pour un commerçant sans variante). Rempli par app:merchants:group / post-import.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Opérations : merchant_group (regroupement des variantes de commerçant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation ADD merchant_group VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_operation_merchant_group ON operation (merchant_group)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_operation_merchant_group');
        $this->addSql('ALTER TABLE operation DROP merchant_group');
    }
}
