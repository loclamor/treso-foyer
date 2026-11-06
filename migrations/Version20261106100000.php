<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot récurrences : recurring_series gagne la clé de regroupement match_key, la direction
 * (dépense/revenu), les mois actifs active_months et updated_at ; unicité (compte, match_key, direction).
 * La table est vide : les colonnes NOT NULL sont posées sans valeur par défaut.
 */
final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Séries récurrentes : match_key, direction, active_months, updated_at et unicité (compte, match_key, direction)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_series ADD match_key VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE recurring_series ADD direction VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE recurring_series ADD active_months JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE recurring_series ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_recurring_series_account_match_direction ON recurring_series (account_id, match_key, direction)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_recurring_series_account_match_direction');
        $this->addSql('ALTER TABLE recurring_series DROP updated_at');
        $this->addSql('ALTER TABLE recurring_series DROP active_months');
        $this->addSql('ALTER TABLE recurring_series DROP direction');
        $this->addSql('ALTER TABLE recurring_series DROP match_key');
    }
}
