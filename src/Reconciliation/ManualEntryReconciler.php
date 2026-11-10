<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Entity\AdblueFill;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\VehicleSource;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Rapprochement des saisies manuelles de dépenses avec les lignes de relevé (voir ADR 0014) :
 * quand le relevé contenant la ligne correspondante est importé (ou quand la saisie est créée
 * après l'import), la ligne du relevé est conservée (elle fait foi) et reçoit les informations
 * de la saisie ; la saisie disparaît.
 *
 * - `reconcile()` automatique : pour chaque saisie en attente du foyer, candidats = opérations
 *   du MÊME compte, non en attente, non rapprochées, issues d'un relevé (label_source NOT NULL),
 *   MÊME montant exact, date dans [spentAt − 2 j, spentAt + 10 j]. Appariement uniquement si la
 *   paire est univoque (1 seul candidat pour la saisie ET ce candidat n'est candidat d'aucune
 *   autre saisie) ; sinon la saisie reste en attente (comptée « ambiguous » si ≥ 1 candidat).
 * - `candidates()` : appariement manuel, fenêtre élargie [spentAt − 5 j, spentAt + 30 j], même
 *   signe, tri : montant exact, puis même compte, puis écart de montant, puis écart de date.
 * - `merge()` : fusion utilisée par l'automatique et le manuel. La ligne du relevé conserve
 *   date, montant, compte, libellé, empreinte, lot ; elle reçoit spentAt (le sien sinon celui
 *   de la saisie), la catégorie de la saisie si elle n'a pas de catégorie manuelle, le véhicule
 *   de la saisie si la ligne n'est pas en manual/ignored, le plein de la saisie si la ligne
 *   n'en a pas (et qu'elle est un débit, ADR 0015), et la note (concaténée, plus
 *   « Saisie : <libellé> » si le libellé saisi diffère). reconciledAt = now, saisie supprimée.
 */
final class ManualEntryReconciler
{
    /** Fenêtre de l'appariement automatique : [spentAt − AUTO_BEFORE_DAYS, spentAt + AUTO_AFTER_DAYS]. */
    public const AUTO_BEFORE_DAYS = 2;
    public const AUTO_AFTER_DAYS = 10;
    /** Ligne CB datée (spentAt extrait du libellé) : écart toléré avec la date saisie. */
    public const AUTO_CARD_DATE_TOLERANCE_DAYS = 2;
    /** Fenêtre des candidats de l'appariement manuel : [spentAt − CANDIDATE_BEFORE_DAYS, spentAt + CANDIDATE_AFTER_DAYS]. */
    public const CANDIDATE_BEFORE_DAYS = 5;
    public const CANDIDATE_AFTER_DAYS = 30;
    public const CANDIDATES_LIMIT = 20;
    /** Alerte du résumé : saisie en attente depuis plus de STALE_AFTER_DAYS jours. */
    public const STALE_AFTER_DAYS = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Appariement automatique de toutes les saisies en attente du foyer.
     * En dry-run, calcule les appariements sans rien écrire.
     */
    public function reconcile(Household $household, bool $dryRun = false): ReconciliationReport
    {
        /** @var list<Operation> $entries */
        $entries = $this->entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.awaitingStatement = true')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $household)
            ->getQuery()->getResult();

        // Candidats automatiques de chaque saisie (même compte, même montant exact, fenêtre -2/+10).
        /** @var array<int, list<Operation>> $candidatesByEntry */
        $candidatesByEntry = [];
        /** @var array<int, list<int>> $ownersByCandidate id ligne => ids des saisies qui la convoitent */
        $ownersByCandidate = [];
        foreach ($entries as $entry) {
            $candidates = $this->autoCandidates($entry);
            $candidatesByEntry[(int) $entry->getId()] = $candidates;
            foreach ($candidates as $candidate) {
                $ownersByCandidate[(int) $candidate->getId()][] = (int) $entry->getId();
            }
        }

        /** @var list<array{0: Operation, 1: Operation}> $pairs */
        $pairs = [];
        $ambiguous = 0;
        foreach ($entries as $entry) {
            $candidates = $candidatesByEntry[(int) $entry->getId()] ?? [];
            if (1 === count($candidates) && 1 === count($ownersByCandidate[(int) $candidates[0]->getId()] ?? [])) {
                $pairs[] = [$entry, $candidates[0]];
            } elseif ([] !== $candidates) {
                ++$ambiguous;
            }
        }
        $pending = count($entries) - count($pairs) - $ambiguous;

        /** @var array<int, int> $matchedLines id saisie => id ligne conservée */
        $matchedLines = [];
        if (!$dryRun) {
            foreach ($pairs as [$entry, $line]) {
                // Id capturé avant le merge : la suppression de la saisie remet son id à null.
                $entryId = (int) $entry->getId();
                $merged = $this->merge($entry, $line);
                $matchedLines[$entryId] = (int) $merged->getId();
            }
        }

        return new ReconciliationReport(count($pairs), $ambiguous, $pending, $matchedLines);
    }

    /**
     * Candidats de l'appariement manuel d'une saisie : même foyer, même signe, pas en attente,
     * pas rapprochés, issus d'un relevé, date dans [spentAt − 5 j, spentAt + 30 j] ; triés par
     * montant exact, puis même compte, puis |écart de montant|, puis |écart de date|.
     *
     * @return list<Operation>
     */
    public function candidates(Operation $entry, int $limit = self::CANDIDATES_LIMIT): array
    {
        $householdId = (int) $entry->getAccount()->getHousehold()->getId();
        $spentAt = $entry->getSpentAt() ?? $entry->getDate();
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id
                FROM operation o
                JOIN account a ON a.id = o.account_id
                WHERE a.household_id = :household::int
                  AND (o.amount > 0) = (:amount > 0)
                  AND NOT o.awaiting_statement
                  AND o.reconciled_at IS NULL
                  AND o.label_source IS NOT NULL
                  AND o.date BETWEEN :from AND :to
                ORDER BY (o.amount = :amount) DESC,
                         (o.account_id = :account) DESC,
                         ABS(o.amount - :amount) ASC,
                         ABS(o.date - :entryDate) ASC,
                         o.id ASC
                LIMIT :limit
                SQL,
            [
                'household' => $householdId,
                'amount' => $entry->getAmount(),
                'account' => $entry->getAccount()->getId(),
                'from' => $spentAt->modify('-'.self::CANDIDATE_BEFORE_DAYS.' days'),
                'to' => $spentAt->modify('+'.self::CANDIDATE_AFTER_DAYS.' days'),
                'entryDate' => $spentAt,
                'limit' => $limit,
            ],
            [
                'household' => ParameterType::INTEGER,
                'amount' => ParameterType::INTEGER,
                'account' => ParameterType::INTEGER,
                'from' => Types::DATE_IMMUTABLE,
                'to' => Types::DATE_IMMUTABLE,
                'entryDate' => Types::DATE_IMMUTABLE,
                'limit' => ParameterType::INTEGER,
            ],
        );

        $operations = [];
        foreach ($rows as $row) {
            $operation = $this->entityManager->find(Operation::class, (int) $row['id']);
            if ($operation instanceof Operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * Fusion d'une saisie en attente avec une ligne de relevé : la ligne conserve ses valeurs
     * (date, montant, compte, libellé, empreinte, lot — elles font foi, même en manuel où
     * compte et montant peuvent différer) et reçoit les informations de la saisie.
     *
     * @throws \InvalidArgumentException si les préconditions ne sont pas remplies
     */
    public function merge(Operation $entry, Operation $statementLine): Operation
    {
        if (!$entry->isAwaitingStatement()) {
            throw new \InvalidArgumentException('Seule une saisie en attente peut être rapprochée.');
        }
        if ($statementLine->isAwaitingStatement()) {
            throw new \InvalidArgumentException('La ligne cible est elle-même une saisie en attente.');
        }
        if (null !== $statementLine->getReconciledAt()) {
            throw new \InvalidArgumentException('La ligne de relevé est déjà rapprochée d’une saisie.');
        }
        if ((int) $entry->getAccount()->getHousehold()->getId() !== (int) $statementLine->getAccount()->getHousehold()->getId()) {
            throw new \InvalidArgumentException('La saisie et la ligne de relevé doivent appartenir au même foyer.');
        }

        // spentAt : celui de la ligne (extrait du libellé CB) s'il existe, sinon celui de la saisie.
        $statementLine->setSpentAt($statementLine->getSpentAt() ?? $entry->getSpentAt());

        // Catégorie : copiée si la ligne n'a pas de catégorie manuelle (source et règle copiées).
        if (null !== $entry->getCategory() && CategorizationSource::Manual !== $statementLine->getCategorizationSource()) {
            $statementLine->setCategory($entry->getCategory());
            $statementLine->setCategorizationSource($entry->getCategorizationSource() ?? CategorizationSource::Manual);
            $statementLine->setAppliedRule($entry->getAppliedRule());
            $statementLine->setCategorizedAt($this->clock->now());
        }

        // Véhicule : copié si la ligne n'est pas en affectation manuelle ni « ignorée ».
        if (null !== $entry->getVehicle()
            && !\in_array($statementLine->getVehicleSource(), [VehicleSource::Manual, VehicleSource::Ignored], true)
        ) {
            $statementLine->assignVehicle($entry->getVehicle(), $entry->getVehicleSource() ?? VehicleSource::Manual);
        }

        // Plein de carburant (ADR 0015) : copié de la saisie si la ligne n'en a pas et est un débit.
        if (!$statementLine->hasFuel() && $entry->hasFuel() && $statementLine->isDebit()) {
            $statementLine->recordFuel((int) $entry->getFuelVolumeMl(), $entry->getFuelFullTank() ?? true, $entry->getOdometerKm());
        }

        // Achat de gaz (ADR 0017) : copié de la saisie si la ligne n'en a pas et est un débit.
        if (!$statementLine->hasGas() && $entry->hasGas() && $statementLine->isDebit()) {
            $statementLine->recordGas((int) $entry->getGasMassG());
        }

        // Note : note de la saisie ; si les deux ont une note distincte, « ligne — saisie » ;
        // si le libellé saisi diffère, on le rajoute sous la forme « Saisie : <libellé> ».
        $note = $statementLine->getNote();
        $entryNote = $entry->getNote();
        if (null !== $entryNote && $entryNote !== $note) {
            $note = null === $note ? $entryNote : $note.' — '.$entryNote;
        }
        if ($entry->getRawLabel() !== $statementLine->getRawLabel()) {
            $saisie = 'Saisie : '.$entry->getRawLabel();
            $note = null === $note ? $saisie : $note."\n".$saisie;
        }
        $statementLine->setNote($note);

        // Remplissage d'AdBlue lié à la saisie (ADR 0016) : passe sur la ligne du relevé.
        $this->entityManager->getRepository(AdblueFill::class)->transferOperation($entry, $statementLine);

        $statementLine->setReconciledAt($this->clock->now());
        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        return $statementLine;
    }

    /**
     * Candidats automatiques d'une saisie : même compte, non en attente, non rapprochés,
     * issus d'un relevé (label_source NOT NULL), même montant exact,
     * date dans [spentAt − AUTO_BEFORE_DAYS, spentAt + AUTO_AFTER_DAYS].
     *
     * @return list<Operation>
     */
    private function autoCandidates(Operation $entry): array
    {
        $spentAt = $entry->getSpentAt() ?? $entry->getDate();

        return $this->entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.account = :account')
            ->andWhere('o.amount = :amount')
            ->andWhere('o.awaitingStatement = false')
            ->andWhere('o.reconciledAt IS NULL')
            ->andWhere('o.labelSource IS NOT NULL')
            ->andWhere('o.date BETWEEN :from AND :to')
            // Ligne CB : sa date d'achat (libellé) doit aussi être proche de la date saisie,
            // sinon un autre achat du même montant débité dans la fenêtre serait absorbé.
            ->andWhere('o.spentAt IS NULL OR o.spentAt BETWEEN :spentFrom AND :spentTo')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $entry->getAccount()->getHousehold())
            ->setParameter('account', $entry->getAccount())
            ->setParameter('amount', $entry->getAmount())
            ->setParameter('from', $spentAt->modify('-'.self::AUTO_BEFORE_DAYS.' days'), Types::DATE_IMMUTABLE)
            ->setParameter('to', $spentAt->modify('+'.self::AUTO_AFTER_DAYS.' days'), Types::DATE_IMMUTABLE)
            ->setParameter('spentFrom', $spentAt->modify('-'.self::AUTO_CARD_DATE_TOLERANCE_DAYS.' days'), Types::DATE_IMMUTABLE)
            ->setParameter('spentTo', $spentAt->modify('+'.self::AUTO_CARD_DATE_TOLERANCE_DAYS.' days'), Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();
    }
}
