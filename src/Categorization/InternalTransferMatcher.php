<?php

declare(strict_types=1);

namespace App\Categorization;

use App\Entity\Category;
use App\Entity\Household;
use App\Entity\InternalTransfer;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\TransferStatus;
use App\Setup\CategoryResolver;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appariement des virements internes du foyer (voir docs/adr/0009-multi-comptes-foyer.md) :
 * une opération débit d'un compte A et une opération crédit d'un compte B ≠ A, montants opposés,
 * dates à ±$days jours, aucune des deux déjà liée à un virement interne ni catégorisée manuellement
 * dans une autre catégorie que « Mouvements internes › Virements entre comptes du foyer ».
 * Préférence aux libellés commençant par VIREMENT ; plusieurs candidats restants ⇒ ambigüe, non apparié.
 */
final class InternalTransferMatcher
{
    public const TRANSFER_CATEGORY = 'Mouvements internes › Virements entre comptes du foyer';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryResolver $categoryResolver,
    ) {
    }

    public function match(Household $household, bool $dryRun = false, int $days = 3): TransferMatchReport
    {
        $transferCategory = $this->categoryResolver->find($household, self::TRANSFER_CATEGORY);
        if (null === $transferCategory || null === $household->getId()) {
            // Sans catégorie « Virements entre comptes du foyer », aucun appariement possible.
            return new TransferMatchReport();
        }

        // Jointure sur montant opposé + fenêtre de dates : pas de boucle N² en PHP.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT d.id AS debit_id, c.id AS credit_id,
                       (d.normalized_label LIKE 'VIREMENT%') AS debit_virement,
                       (c.normalized_label LIKE 'VIREMENT%') AS credit_virement
                FROM operation d
                JOIN account ad ON ad.id = d.account_id AND ad.household_id = :household::int
                JOIN operation c ON c.amount = -d.amount
                JOIN account ac ON ac.id = c.account_id AND ac.household_id = :household::int AND ac.id <> d.account_id
                WHERE d.amount < 0
                  AND d.internal_transfer_id IS NULL
                  AND c.internal_transfer_id IS NULL
                  AND NOT d.awaiting_statement
                  AND NOT c.awaiting_statement
                  AND d.date >= c.date - make_interval(days => :days::int)
                  AND d.date <= c.date + make_interval(days => :days::int)
                  AND (d.categorization_source IS DISTINCT FROM 'manual' OR d.category_id = :transferCategory::int)
                  AND (c.categorization_source IS DISTINCT FROM 'manual' OR c.category_id = :transferCategory::int)
                SQL,
            [
                'household' => $household->getId(),
                'days' => $days,
                'transferCategory' => $transferCategory->getId(),
            ],
            [
                'household' => ParameterType::INTEGER,
                'days' => ParameterType::INTEGER,
                'transferCategory' => ParameterType::INTEGER,
            ],
        );

        $pairs = $this->selectPairs($rows);

        $report = new TransferMatchReport(matched: count($pairs['matched']), ambiguous: count($pairs['ambiguous']));

        $batch = 0;
        foreach ($pairs['matched'] as [$debitId, $creditId]) {
            $debit = $this->entityManager->find(Operation::class, $debitId);
            $credit = $this->entityManager->find(Operation::class, $creditId);
            if (!$debit instanceof Operation || !$credit instanceof Operation) {
                continue;
            }
            $transfer = new InternalTransfer(TransferStatus::Auto);
            $transfer->addOperation($debit);
            $transfer->addOperation($credit);
            $debit->categorize($transferCategory, CategorizationSource::Transfer);
            $credit->categorize($transferCategory, CategorizationSource::Transfer);
            if (!$dryRun) {
                $this->entityManager->persist($transfer);
                if (++$batch >= self::BATCH_SIZE) {
                    $batch = 0;
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            }
        }
        if (!$dryRun && $batch > 0) {
            $this->entityManager->flush();
        }

        return $report;
    }

    /**
     * Défait une paire : les opérations liées sont déliées et décatégorisées
     * si leur source était « transfer ».
     */
    public function unlink(InternalTransfer $transfer): void
    {
        foreach ($transfer->getOperations()->toArray() as $operation) {
            $operation->setInternalTransfer(null);
            if (CategorizationSource::Transfer === $operation->getCategorizationSource()) {
                $operation->uncategorize();
            }
        }
        $this->entityManager->remove($transfer);
        $this->entityManager->flush();
    }

    /**
     * @param list<array{debit_id: int|string, credit_id: int|string, debit_virement: bool|string, credit_virement: bool|string}> $rows
     * @return array{matched: list<array{0: int, 1: int}>, ambiguous: list<int>}
     */
    private function selectPairs(array $rows): array
    {
        /** @var array<int, list<array{credit_id: int, debit_virement: bool, credit_virement: bool}>> $byDebit */
        $byDebit = [];
        foreach ($rows as $row) {
            $byDebit[(int) $row['debit_id']][] = [
                'credit_id' => (int) $row['credit_id'],
                'debit_virement' => $this->toBool($row['debit_virement']),
                'credit_virement' => $this->toBool($row['credit_virement']),
            ];
        }

        $matched = [];
        $ambiguous = [];
        foreach ($byDebit as $debitId => $candidates) {
            if (1 === count($candidates)) {
                $matched[] = [$debitId, $candidates[0]['credit_id']];
                continue;
            }
            // Plusieurs candidats : préférence aux libellés VIREMENT, sinon ambigü.
            $virements = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => $c['debit_virement'] || $c['credit_virement'],
            ));
            if (1 === count($virements)) {
                $matched[] = [$debitId, $virements[0]['credit_id']];
            } else {
                $ambiguous[] = $debitId;
            }
        }

        // Un crédit convoité par plusieurs débits rend toutes les paires concernées ambigües.
        $creditOwners = [];
        $dropped = [];
        foreach ($matched as $i => [$debitId, $creditId]) {
            if (isset($creditOwners[$creditId])) {
                $dropped[$i] = true;
                foreach ($matched as $j => [, $otherCreditId]) {
                    if ($otherCreditId === $creditId) {
                        $dropped[$j] = true;
                    }
                }
                $ambiguous[] = $debitId;
            } else {
                $creditOwners[$creditId] = $debitId;
            }
        }
        $matched = array_values(array_filter(
            $matched,
            static fn (array $pair, int $i): bool => !isset($dropped[$i]),
            ARRAY_FILTER_USE_BOTH,
        ));

        return ['matched' => $matched, 'ambiguous' => array_values(array_unique($ambiguous))];
    }

    private function toBool(bool|string $value): bool
    {
        return true === $value || 't' === $value || '1' === $value || 1 === $value;
    }
}
