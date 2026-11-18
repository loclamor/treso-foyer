<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Véhicule par défaut d'une période labellisée (voir docs/adr/0018) :
 * - vehicle_id : véhicule des dépenses de transport de la période (FK nullable, SET NULL) ;
 * - vehicle_overrides_rules : la période l'emporte aussi sur les règles désignant un véhicule.
 */
final class Version20261116100334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Périodes labellisées (ADR 0018) : véhicule par défaut (vehicle_id, vehicle_overrides_rules)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tagged_period ADD vehicle_overrides_rules BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE tagged_period ADD vehicle_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tagged_period ADD CONSTRAINT FK_6FA8A0DC545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6FA8A0DC545317D1 ON tagged_period (vehicle_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tagged_period DROP CONSTRAINT FK_6FA8A0DC545317D1');
        $this->addSql('DROP INDEX IDX_6FA8A0DC545317D1');
        $this->addSql('ALTER TABLE tagged_period DROP vehicle_overrides_rules');
        $this->addSql('ALTER TABLE tagged_period DROP vehicle_id');
    }
}
