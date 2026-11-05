<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidation des libellés : source du libellé, date d\'enrichissement, compteur d\'enrichissement par import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch ADD enriched_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE operation ADD label_source VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE operation ADD enriched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Opérations déjà importées : la source du libellé est le format de leur fichier d'origine.
        $this->addSql('UPDATE operation o SET label_source = b.format FROM import_batch b WHERE o.import_batch_id = b.id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch DROP enriched_count');
        $this->addSql('ALTER TABLE operation DROP label_source');
        $this->addSql('ALTER TABLE operation DROP enriched_at');
    }
}
