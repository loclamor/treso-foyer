<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Étape 8 : emplacement graphique chart_slot (1-8) des racines de dépense.
 * L'index unique est PARTIEL (WHERE chart_slot IS NOT NULL) : non exprimable dans le
 * mapping Doctrine, l'entity déclare un index simple de même nom/colonnes (comme
 * l'index GIN pg_trgm) pour que doctrine:migrations:diff reste vide.
 * Initialise les racines de dépense existantes (par nom) : chart_slot + palette validée.
 */
final class Version20261104190000 extends AbstractMigration
{
    private const ROOT_SLOTS = [
        // name => [chart_slot, color]
        'Alimentation' => [1, '#2a78d6'],
        'Logement' => [2, '#eb6834'],
        'Shopping' => [3, '#1baf7a'],
        'Transport' => [4, '#eda100'],
        'Impôts & taxes' => [5, '#e87ba4'],
        'Abonnements & télécoms' => [6, '#008300'],
        'Santé' => [7, '#6250d6'],
        'Banque & assurances' => [8, '#e34948'],
    ];

    public function getDescription(): string
    {
        return 'Catégorie : chart_slot (1-8, unique par foyer, racines de dépense) + palette des racines par défaut';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD chart_slot INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_category_household_chart_slot ON category (household_id, chart_slot) WHERE chart_slot IS NOT NULL');
        foreach (self::ROOT_SLOTS as $name => [$slot, $color]) {
            $this->addSql(
                "UPDATE category SET chart_slot = $slot, color = '$color' WHERE parent_id IS NULL AND kind = 'expense' AND name = "."'".$name."'",
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_category_household_chart_slot');
        $this->addSql('ALTER TABLE category DROP chart_slot');
    }
}
