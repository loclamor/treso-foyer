<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gaz en bouteille (voir docs/adr/0017) :
 * - `operation.gas_mass_g` (grammes, > 0, null = pas d'achat de gaz) ;
 * - `category.gas` : poste gaz (affiche les champs masse / prix au kg dans le front) ;
 * - distinction Électricité / Gaz dans « Logement » : la sous-catégorie « Énergie » est
 *   renommée « Électricité » et une sous-catégorie « Gaz » (gas = true) est créée, comme
 *   par app:setup ; les opérations « GRDF » basculent sur « Gaz » ;
 * - règles par défaut : EDF & co → « Logement › Électricité » (pattern débarrassé de GRDF),
 *   GRDF → « Logement › Gaz » ;
 * - contrainte CHECK de cohérence, posée en SQL brut (hors mapping : DBAL ne relit pas les
 *   CHECK de PostgreSQL, le diff de schéma reste vide).
 */
final class Version20261110090000 extends AbstractMigration
{
    private const OLD_ENERGY_PATTERN = '^(ACHAT CB|[A-Z]+ CARTE|PRELEVEMENT DE)\b.*\b(EDF|ENGIE|EKWATEUR|PLANETE OUI|GRDF|ENEDIS|ENERCOOP)\b';
    private const ELECTRICITY_PATTERN = '^(ACHAT CB|[A-Z]+ CARTE|PRELEVEMENT DE)\b.*\b(EDF|ENGIE|EKWATEUR|PLANETE OUI|ENEDIS|ENERCOOP)\b';
    private const GAS_PATTERN = '^(ACHAT CB|[A-Z]+ CARTE|PRELEVEMENT DE)\b.*\b(GRDF)\b';

    public function getDescription(): string
    {
        return 'Gaz en bouteille (ADR 0017) : operation.gas_mass_g, category.gas, « Logement › Électricité »/« Gaz », règles EDF/GRDF';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation ADD gas_mass_g INT DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD gas BOOLEAN NOT NULL DEFAULT false');
        // Cohérence : masse nulle ou strictement positive. Non déclarée dans le mapping.
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT ck_operation_gas CHECK (gas_mass_g IS NULL OR gas_mass_g > 0)');

        // « Logement › Énergie » devient « Logement › Électricité » (si « Électricité » n'existe pas déjà).
        $this->addSql("UPDATE category c SET name = 'Électricité'
            FROM category r
            WHERE c.parent_id = r.id AND r.parent_id IS NULL AND r.name = 'Logement'
              AND c.name = 'Énergie'
              AND NOT EXISTS (SELECT 1 FROM category e WHERE e.parent_id = r.id AND e.name = 'Électricité')");
        // Cas où « Électricité » pré-existe : les opérations « Énergie » y sont déplacées, « Énergie » archivée.
        $this->addSql("UPDATE operation o SET category_id = e.id
            FROM category e, category r, category c
            WHERE e.parent_id = r.id AND r.parent_id IS NULL AND r.name = 'Logement' AND e.name = 'Électricité'
              AND c.parent_id = r.id AND c.name = 'Énergie'
              AND o.category_id = c.id");
        $this->addSql("UPDATE category c SET archived = true
            FROM category r
            WHERE c.parent_id = r.id AND r.parent_id IS NULL AND r.name = 'Logement'
              AND c.name = 'Énergie'
              AND EXISTS (SELECT 1 FROM category e WHERE e.parent_id = r.id AND e.name = 'Électricité')");

        // Création de « Logement › Gaz » (gas = true), même résultat que app:setup.
        $this->addSql("INSERT INTO category (household_id, parent_id, name, kind, position, archived, vehicle_related, fuel, financing, gas, created_at)
            SELECT r.household_id, r.id, 'Gaz', r.kind,
                   (SELECT COALESCE(MAX(s.position) + 1, 0) FROM category s WHERE s.parent_id = r.id),
                   false, false, false, false, true, NOW()
            FROM category r
            WHERE r.parent_id IS NULL AND r.name = 'Logement'
              AND NOT EXISTS (SELECT 1 FROM category e WHERE e.parent_id = r.id AND e.name = 'Gaz')");
        $this->addSql("UPDATE category c SET gas = true
            FROM category r
            WHERE c.parent_id = r.id AND r.parent_id IS NULL AND r.name = 'Logement' AND c.name = 'Gaz'");

        // Les prélèvements GRDF (ex-« Énergie ») basculent sur « Gaz ».
        $this->addSql("UPDATE operation o SET category_id = g.id
            FROM category g, category r, category e
            WHERE g.parent_id = r.id AND r.parent_id IS NULL AND r.name = 'Logement' AND g.name = 'Gaz'
              AND e.parent_id = r.id AND e.name = 'Électricité'
              AND o.category_id = e.id
              AND o.normalized_label ~* '\mGRDF\M'");

        // Règle par défaut « Énergie » : pattern rétréci à l'électricité (sans GRDF).
        $this->addSql('UPDATE categorization_rule SET pattern = :electricity
            WHERE match_field = \'label\' AND match_type = \'regex\' AND pattern = :old', [
            'electricity' => self::ELECTRICITY_PATTERN,
            'old' => self::OLD_ENERGY_PATTERN,
        ]);
        // Règle par défaut « Gaz » : GRDF.
        $this->addSql('INSERT INTO categorization_rule (match_field, match_type, pattern, direction, amount_min, amount_max, priority, enabled, created_at, household_id, category_id, account_id)
            SELECT \'label\', \'regex\', :gas, \'any\', NULL, NULL, 50, true, NOW(), g.household_id, g.id, NULL
            FROM category g, category r
            WHERE g.parent_id = r.id AND r.parent_id IS NULL AND r.name = \'Logement\' AND g.name = \'Gaz\'
              AND NOT EXISTS (SELECT 1 FROM categorization_rule cr WHERE cr.category_id = g.id AND cr.match_field = \'label\' AND cr.match_type = \'regex\' AND cr.pattern = :gas2)', [
            'gas' => self::GAS_PATTERN,
            'gas2' => self::GAS_PATTERN,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Les sous-catégories créées/renommées sont conservées (elles peuvent porter des opérations).
        $this->addSql('ALTER TABLE operation DROP CONSTRAINT ck_operation_gas');
        $this->addSql('ALTER TABLE operation DROP gas_mass_g');
        $this->addSql('ALTER TABLE category DROP gas');
    }
}
