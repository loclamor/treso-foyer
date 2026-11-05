<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Compte : date de clôture (plus de relevé attendu après ce mois). */
final class Version20261105160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte : date de clôture closed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD closed_at DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP closed_at');
    }
}
