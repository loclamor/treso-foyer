<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Financement des véhicules (ADR 0011) :
 * - `category.financing` : poste rattaché au véhicule mais exclu du coût d'usage ;
 * - sous-catégorie « Transport › Crédit véhicule » (vehicle_related + financing) créée pour
 *   chaque foyer qui a une racine « Transport » et ne l'a pas encore (même résultat que app:setup).
 */
final class Version20261109150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Financement des véhicules (ADR 0011) : category.financing + sous-catégorie « Transport › Crédit véhicule »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD financing BOOLEAN NOT NULL DEFAULT false');
        $this->addSql("INSERT INTO category (household_id, parent_id, name, kind, position, archived, vehicle_related, fuel, financing, created_at)
            SELECT r.household_id, r.id, 'Crédit véhicule', r.kind,
                   (SELECT COALESCE(MAX(s.position) + 1, 0) FROM category s WHERE s.parent_id = r.id),
                   false, true, false, true, NOW()
            FROM category r
            WHERE r.parent_id IS NULL AND r.name = 'Transport'
              AND NOT EXISTS (SELECT 1 FROM category e WHERE e.parent_id = r.id AND e.name = 'Crédit véhicule')");
    }

    public function down(Schema $schema): void
    {
        // La sous-catégorie créée est conservée (elle peut porter des opérations) : seul le drapeau disparaît.
        $this->addSql('ALTER TABLE category DROP financing');
    }
}
