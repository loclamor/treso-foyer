<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pleins de carburant (voir docs/adr/0015) :
 * - `operation.fuel_volume_ml` (mL, > 0), `operation.fuel_full_tank` (booléen, non nul ssi
 *   volume non nul), `operation.odometer_km` (≥ 0, optionnel — point de compteur du plein,
 *   fusionné avec `odometer_reading` par OdometerReadingRepository) ;
 * - `category.fuel` : poste carburant (affiche les champs du plein dans le front) ;
 * - contrainte CHECK de cohérence volume / plein complet / kilométrage, posée en SQL brut :
 *   elle n'est PAS déclarée dans le mapping (DBAL ne relit pas les CHECK de PostgreSQL),
 *   le diff de schéma reste donc vide.
 * Données : les sous-catégories « Carburant » existantes et vehicle_related passent fuel = true.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pleins de carburant (ADR 0015) : operation.fuel_volume_ml/fuel_full_tank/odometer_km, category.fuel, CHECK de cohérence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation ADD fuel_volume_ml INT DEFAULT NULL');
        $this->addSql('ALTER TABLE operation ADD fuel_full_tank BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE operation ADD odometer_km INT DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD fuel BOOLEAN NOT NULL DEFAULT false');
        // Postes « Carburant » existants liés à un véhicule : flag fuel.
        $this->addSql("UPDATE category SET fuel = true WHERE name = 'Carburant' AND vehicle_related");
        // Cohérence : soit aucun champ de plein, soit un volume > 0 avec plein-complet renseigné
        // et un kilométrage absent ou ≥ 0. Non déclarée dans le mapping (diff de schéma vide).
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT ck_operation_fuel CHECK (
            (fuel_volume_ml IS NULL AND fuel_full_tank IS NULL AND odometer_km IS NULL)
            OR (fuel_volume_ml > 0 AND fuel_full_tank IS NOT NULL AND (odometer_km IS NULL OR odometer_km >= 0))
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation DROP CONSTRAINT ck_operation_fuel');
        $this->addSql('ALTER TABLE operation DROP fuel_volume_ml');
        $this->addSql('ALTER TABLE operation DROP fuel_full_tank');
        $this->addSql('ALTER TABLE operation DROP odometer_km');
        $this->addSql('ALTER TABLE category DROP fuel');
    }
}
