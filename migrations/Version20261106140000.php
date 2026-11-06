<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saisie manuelle de dépenses + rapprochement avec les relevés (voir docs/adr/0014) :
 * - `spent_at` : date réelle de la dépense (saisie manuelle ou extraite du libellé CB),
 *   NULL si inconnue (les dates CB existantes sont posées par `app:operations:spent-at`) ;
 * - `awaiting_statement` : saisie manuelle pas encore rapprochée (index partiel) ;
 * - `reconciled_at` : posé sur la ligne de relevé ayant absorbé une saisie ;
 * - index fonctionnel sur la date effective COALESCE(spent_at, date), créé en SQL brut :
 *   comme l'index GIN pg_trgm, il n'est pas déclaré dans le mapping (expression), il est
 *   donc ignoré du diff de schéma.
 */
final class Version20261106140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Opérations : spent_at, awaiting_statement, reconciled_at (saisie manuelle, ADR 0014)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation ADD spent_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE operation ADD awaiting_statement BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE operation ADD reconciled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Pas de backfill ici : spent_at reste NULL tant qu'aucune date réelle n'est connue
        // (sinon une ligne sans date CB masquerait la date d'une saisie lors du rapprochement).
        // Les dates CB des opérations existantes sont posées par `app:operations:spent-at`.
        // Index partiel (déclaré aussi dans le mapping, options where repris de pg_indexes).
        $this->addSql('CREATE INDEX idx_operation_awaiting_statement ON operation (awaiting_statement) WHERE awaiting_statement');
        // Index fonctionnel sur la date effective, en SQL brut. L'index auto de la clé
        // étrangère sur account_id est supprimé : il est déclaré dans le mapping via cet
        // index (pg_index relit l'index fonctionnel comme portant sur account_id seul) et
        // idx_operation_account_date (account_id, date) couvre déjà le préfixe account_id.
        $this->addSql('DROP INDEX IDX_1981A66D9B6B5FBA');
        $this->addSql('CREATE INDEX idx_operation_account_effective_date ON operation (account_id, COALESCE(spent_at, date))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_operation_account_effective_date');
        $this->addSql('CREATE INDEX IDX_1981A66D9B6B5FBA ON operation (account_id)');
        $this->addSql('DROP INDEX idx_operation_awaiting_statement');
        $this->addSql('ALTER TABLE operation DROP reconciled_at');
        $this->addSql('ALTER TABLE operation DROP awaiting_statement');
        $this->addSql('ALTER TABLE operation DROP spent_at');
    }
}
