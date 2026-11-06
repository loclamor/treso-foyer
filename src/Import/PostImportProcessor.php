<?php

declare(strict_types=1);

namespace App\Import;

use App\Categorization\CategorizeOptions;
use App\Categorization\Categorizer;
use App\Categorization\InternalTransferMatcher;
use App\Entity\Household;
use App\Entity\Operation;
use App\Recurrence\RecurrenceDetector;
use App\Repository\OperationRepository;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Post-traitement après un import réel (extrait de ImportCommand, utilisé aussi par
 * POST /api/imports) : appariement des virements internes du foyer, puis catégorisation
 * des opérations non catégorisées et des opérations enrichies non manuelles
 * (leur commerçant a pu changer), puis affectation automatique des véhicules,
 * puis détection des séries récurrentes.
 */
final class PostImportProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternalTransferMatcher $transferMatcher,
        private readonly Categorizer $categorizer,
        private readonly VehicleAssigner $vehicleAssigner,
        private readonly RecurrenceDetector $recurrenceDetector,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(Household $household): PostImportReport
    {
        $transferReport = $this->transferMatcher->match($household);

        /** @var OperationRepository $operationRepository */
        $operationRepository = $this->entityManager->getRepository(Operation::class);
        $operations = $operationRepository->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.category IS NULL OR (o.enrichedAt IS NOT NULL AND (o.categorizationSource IS NULL OR o.categorizationSource <> \'manual\'))')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $household)
            ->getQuery()->toIterable();
        $categorizationReport = $this->categorizer->categorize(
            $operations,
            new CategorizeOptions(onlyUncategorized: false),
        );

        // Affectation des véhicules après le flush de la catégorisation (le Categorizer
        // fait des clear() : le VehicleAssigner travaille en DBAL, hors UnitOfWork).
        $this->entityManager->flush();
        $vehicleReport = $this->vehicleAssigner->sync($household);

        // Détection des récurrences après le flush (DBAL, hors UnitOfWork), à la date du jour.
        $recurrenceReport = $this->recurrenceDetector->sync($household, $this->clock->now());

        return new PostImportReport(
            transfersMatched: $transferReport->matched,
            transfersAmbiguous: $transferReport->ambiguous,
            categorization: $categorizationReport,
            vehicles: $vehicleReport,
            recurrences: $recurrenceReport,
        );
    }
}
