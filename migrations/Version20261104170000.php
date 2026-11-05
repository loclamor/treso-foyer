<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unicité des catégories : deux racines (parent_id NULL) de même nom doivent aussi être refusées.
 * PostgreSQL 15+ : NULLS NOT DISTINCT (non exprimable dans le mapping Doctrine).
 */
final class Version20261104170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories : unicité (foyer, parent, nom) avec NULLS NOT DISTINCT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_category_household_parent_name');
        $this->addSql('CREATE UNIQUE INDEX uq_category_household_parent_name ON category (household_id, parent_id, name) NULLS NOT DISTINCT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_category_household_parent_name');
        $this->addSql('CREATE UNIQUE INDEX uq_category_household_parent_name ON category (household_id, parent_id, name)');
    }
}
