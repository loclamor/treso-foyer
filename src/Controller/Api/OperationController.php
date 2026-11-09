<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\Filter\OperationFilter;
use App\Api\HouseholdProvider;
use App\Api\Input\BulkCategorizeInput;
use App\Api\Input\BulkVehicleAssignInput;
use App\Api\Input\CategorizeOperationInput;
use App\Api\Input\DuplicateInput;
use App\Api\Input\FuelInput;
use App\Api\Input\OperationCreateInput;
use App\Api\Input\OperationPatchInput;
use App\Api\Input\ReconcileInput;
use App\Api\Input\VehicleAssignInput;
use App\Api\NotFoundException;
use App\Api\Pagination;
use App\Api\Presenter\OperationPresenter;
use App\Api\Presenter\RulePresenter;
use App\Api\Scope;
use App\Api\ValidationException;
use App\Categorization\CategorizeOptions;
use App\Categorization\Categorizer;
use App\Categorization\ManualCategorizer;
use App\Entity\Account;
use App\Entity\AdblueFill;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use App\Enum\VehicleSource;
use App\Import\LabelNormalizer;
use App\Reconciliation\ManualEntryReconciler;
use App\Repository\OdometerReadingRepository;
use App\Vehicle\OdometerCoherence;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class OperationController extends AbstractController
{
    #[Route('/api/operations', name: 'api_operations', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        EntityManagerInterface $entityManager,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $filter = OperationFilter::fromRequest($request, $household, $entityManager)->withAccountIds($accountIds);

        $base = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->setParameter('household', $household);
        $scope->apply($base, $accountIds);
        $filter->apply($base);

        $pagination = Pagination::fromRequest($request);

        $total = (int) (clone $base)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $sums = $filter->sums($base);

        $rows = $base
            ->select('o', 'a', 'c')
            ->leftJoin('o.category', 'c')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage);
        $filter->applyOrderBy($rows);
        $operations = array_map(
            static fn (mixed $row): Operation => \is_array($row) ? $row[0] : $row,
            $rows->getQuery()->getResult(),
        );

        return new JsonResponse($pagination->envelope(
            array_map(static fn (Operation $operation): array => $operationPresenter->present($operation), $operations),
            $total,
        ) + ['sums' => $sums]);
    }

    /**
     * POST /api/operations : saisie manuelle d'une dépense (ADR 0014). Réponse 201 :
     * {"operation": Operation, "reconciled": bool} — reconciled = true si la saisie a été
     * immédiatement absorbée par une ligne de relevé déjà importée (operation = cette ligne).
     */
    #[Route('/api/operations', name: 'api_operations_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] OperationCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        LabelNormalizer $labelNormalizer,
        Categorizer $categorizer,
        VehicleAssigner $vehicleAssigner,
        ManualEntryReconciler $reconciler,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();

        $account = $entityManager->getRepository(Account::class)->findOneBy(['id' => $input->accountId, 'household' => $household]);
        if (!$account instanceof Account) {
            throw new ValidationException([['field' => 'accountId', 'message' => 'Compte introuvable dans le foyer.']]);
        }
        if (null !== $account->getClosedAt()) {
            throw new ValidationException([['field' => 'accountId', 'message' => 'Compte clôturé : aucune saisie possible.']]);
        }
        $category = null === $input->categoryId ? null : $this->findCategory($entityManager, $household, $input->categoryId);
        $vehicle = null === $input->vehicleId ? null : $this->findVehicle($entityManager, $household, $input->vehicleId);

        // Plein de carburant (ADR 0015) : odometerKm exige un volume ; un plein n'est
        // possible que sur un débit (montant négatif).
        if (null !== $input->odometerKm && null === $input->fuelVolumeMl) {
            throw new ValidationException([['field' => 'odometerKm', 'message' => 'odometerKm ne peut être fourni sans fuelVolumeMl.']]);
        }
        if (null !== $input->fuelVolumeMl && (int) $input->amount > 0) {
            throw new ValidationException([['field' => 'fuelVolumeMl', 'message' => 'Un plein n’est possible que sur un débit (montant négatif).']]);
        }

        $spentAt = new \DateTimeImmutable((string) $input->spentAt);
        $normalized = $labelNormalizer->normalize((string) $input->label);
        $operation = Operation::manualEntry(
            $account,
            $spentAt,
            (int) $input->amount,
            (string) $input->label,
            $normalized->normalized,
            $normalized->merchant,
        );
        if (null !== $input->note) {
            $operation->setNote($input->note);
        }
        if (null !== $category) {
            $operation->categorize($category, CategorizationSource::Manual);
        }
        if (null !== $vehicle) {
            $operation->assignVehicle($vehicle, VehicleSource::Manual);
        }
        if (null !== $input->fuelVolumeMl) {
            $operation->recordFuel($input->fuelVolumeMl, $input->fuelFullTank ?? true, $input->odometerKm);
        }
        $entityManager->persist($operation);
        $entityManager->flush();

        // Affectation automatique du véhicule, puis catégorisation automatique de cette
        // seule opération si aucune catégorie n'a été fournie.
        $vehicleAssigner->sync($household);
        if (null === $category) {
            $categorizer->categorize([$operation], new CategorizeOptions(onlyUncategorized: true));
            $entityManager->flush();
        }
        // sync() écrit en DBAL, hors UnitOfWork : on recharge l'opération avant la suite.
        $entityManager->refresh($operation);

        // Tentative immédiate de rapprochement (cas : le relevé était déjà importé).
        $entryId = (int) $operation->getId();
        $report = $reconciler->reconcile($household);
        $entityManager->clear();
        if (isset($report->matchedLines[$entryId])) {
            $line = $entityManager->find(Operation::class, $report->matchedLines[$entryId]);
            \assert($line instanceof Operation);

            return new JsonResponse(['operation' => $operationPresenter->present($line), 'reconciled' => true], Response::HTTP_CREATED);
        }
        $operation = $entityManager->find(Operation::class, $entryId);
        \assert($operation instanceof Operation);

        return new JsonResponse(['operation' => $operationPresenter->present($operation), 'reconciled' => false], Response::HTTP_CREATED);
    }

    #[Route('/api/operations/bulk-categorize', name: 'api_operations_bulk_categorize', methods: ['POST'])]
    public function bulkCategorize(
        #[MapRequestPayload] BulkCategorizeInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $household = $householdProvider->get();
        $category = $this->findCategory($entityManager, $household, (int) $input->categoryId);

        $operations = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.id IN (:ids)')
            ->setParameter('household', $household)
            ->setParameter('ids', $input->ids)
            ->getQuery()->getResult();
        foreach ($operations as $operation) {
            \assert($operation instanceof Operation);
            $operation->categorize($category, CategorizationSource::Manual);
        }
        $entityManager->flush();
        // La catégorie ayant changé, resynchronise l'axe véhicule du foyer.
        $vehicleAssigner->sync($household);

        return new JsonResponse(['updatedCount' => count($operations)]);
    }

    #[Route('/api/operations/{id}/vehicle', name: 'api_operation_vehicle', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function assignVehicle(
        int $id,
        #[MapRequestPayload] VehicleAssignInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        VehicleAssigner $vehicleAssigner,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $operation = $this->findOperation($entityManager, $household, $id);

        if ($input->ignore) {
            // Opération volontairement sans véhicule : sort du compteur « à affecter ».
            $operation->ignoreVehicle();
            $entityManager->flush();
        } elseif (null === $input->vehicleId) {
            // Retire l'affectation manuelle (ou « ignorée ») puis resynchronise l'axe véhicule.
            $operation->unassignVehicle();
            $entityManager->flush();
            $vehicleAssigner->sync($household);
        } else {
            $operation->assignVehicle($this->findVehicle($entityManager, $household, $input->vehicleId), VehicleSource::Manual);
            $entityManager->flush();
        }
        // sync() écrit en DBAL, hors UnitOfWork : on recharge l'opération avant de la présenter.
        $entityManager->refresh($operation);

        return new JsonResponse($operationPresenter->present($operation));
    }

    #[Route('/api/operations/bulk-vehicle', name: 'api_operations_bulk_vehicle', methods: ['POST'])]
    public function bulkAssignVehicle(
        #[MapRequestPayload] BulkVehicleAssignInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $household = $householdProvider->get();
        $vehicle = null === $input->vehicleId ? null : $this->findVehicle($entityManager, $household, $input->vehicleId);

        $operations = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.id IN (:ids)')
            ->setParameter('household', $household)
            ->setParameter('ids', $input->ids)
            ->getQuery()->getResult();
        foreach ($operations as $operation) {
            \assert($operation instanceof Operation);
            if ($input->ignore) {
                $operation->ignoreVehicle();
            } elseif (null === $vehicle) {
                $operation->unassignVehicle();
            } else {
                $operation->assignVehicle($vehicle, VehicleSource::Manual);
            }
        }
        $entityManager->flush();
        // vehicleId null : les affectations manuelles retirées laissent la place à l'auto.
        $vehicleAssigner->sync($household);

        return new JsonResponse(['updatedCount' => count($operations)]);
    }

    #[Route('/api/operations/{id}', name: 'api_operation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, HouseholdProvider $householdProvider, EntityManagerInterface $entityManager, OperationPresenter $operationPresenter): JsonResponse
    {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);

        return new JsonResponse($operationPresenter->present($operation));
    }

    #[Route('/api/operations/{id}', name: 'api_operation_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] OperationPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        LabelNormalizer $labelNormalizer,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $operation = $this->findOperation($entityManager, $household, $id);

        // accountId / spentAt / amount / label ne sont modifiables que sur une saisie en attente ;
        // leur présence est détectée sur le corps brut (champ absent = inchangé).
        $body = json_decode((string) $request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $entryFields = ['accountId', 'spentAt', 'amount', 'label'];
        $hasEntryFields = [] !== array_intersect(array_keys($body), $entryFields);
        if ($hasEntryFields && !$operation->isAwaitingStatement()) {
            throw new BadRequestException('Seule une saisie manuelle en attente peut modifier accountId, spentAt, amount ou label.', 'not_a_manual_entry');
        }

        $operation->setNote($input->note);
        if ($hasEntryFields) {
            if (array_key_exists('accountId', $body)) {
                $account = $entityManager->getRepository(Account::class)->findOneBy(['id' => $input->accountId, 'household' => $household]);
                if (!$account instanceof Account) {
                    throw new ValidationException([['field' => 'accountId', 'message' => 'Compte introuvable dans le foyer.']]);
                }
                $operation->setAccount($account);
            }
            if (array_key_exists('spentAt', $body)) {
                // spentAt modifie aussi la date (la date du relevé n'a de sens que pour une ligne importée).
                $spentAt = new \DateTimeImmutable((string) $input->spentAt);
                $operation->setSpentAt($spentAt);
                $operation->setDate($spentAt);
            }
            if (array_key_exists('amount', $body)) {
                $operation->setAmount((int) $input->amount);
                // Un plein n'existe que sur un débit (ADR 0015) : passer la saisie en crédit le retire.
                if ($operation->isCredit() && $operation->hasFuel()) {
                    $operation->clearFuel();
                }
            }
            if (array_key_exists('label', $body)) {
                $normalized = $labelNormalizer->normalize((string) $input->label);
                $operation->setRawLabel((string) $input->label);
                $operation->setNormalizedLabel($normalized->normalized);
                $operation->setMerchant($normalized->merchant);
            }
        }
        $entityManager->flush();

        return new JsonResponse($operationPresenter->present($operation));
    }

    /** DELETE /api/operations/{id} : uniquement une saisie manuelle en attente (204), sinon 400. */
    #[Route('/api/operations/{id}', name: 'api_operation_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
    ): Response {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);
        if (!$operation->isAwaitingStatement()) {
            throw new BadRequestException('Seule une saisie manuelle en attente peut être supprimée.', 'not_a_manual_entry');
        }
        $entityManager->remove($operation);
        $entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * PUT /api/operations/{id}/fuel : enregistre (remplace) le plein de l'opération
     * (ADR 0015) ⇒ 200 {"operation": Operation, "warning"?}. 400 `not_a_debit` si crédit.
     * Si un kilométrage est fourni et que l'opération a un véhicule, un `warning` est ajouté
     * quand il est incohérent avec les points de compteur voisins (point de l'opération exclu).
     */
    #[Route('/api/operations/{id}/fuel', name: 'api_operation_fuel', requirements: ['id' => '\\d+'], methods: ['PUT'])]
    public function recordFuel(
        int $id,
        #[MapRequestPayload] FuelInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        OdometerReadingRepository $odometerReadingRepository,
        OdometerCoherence $odometerCoherence,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $operation = $this->findOperation($entityManager, $household, $id);
        if ($operation->isCredit()) {
            throw new BadRequestException('Un plein ne peut être enregistré que sur un débit.', 'not_a_debit');
        }

        $operation->recordFuel((int) $input->volumeMl, $input->fullTank ?? true, $input->odometerKm);
        $entityManager->flush();

        $payload = ['operation' => $operationPresenter->present($operation)];
        $vehicle = $operation->getVehicle();
        if (null !== $input->odometerKm && null !== $vehicle) {
            // Le point de l'opération elle-même est exclu de la comparaison.
            $points = $odometerReadingRepository->findPointsByVehicle($vehicle, $id);
            $warning = $odometerCoherence->warning($points, $operation->getEffectiveDate(), (int) $input->odometerKm);
            if (null !== $warning) {
                $payload['warning'] = $warning;
            }
        }

        return new JsonResponse($payload);
    }

    /** DELETE /api/operations/{id}/fuel : supprime le plein de l'opération (ADR 0015) ⇒ 200 {"operation": Operation}. */
    #[Route('/api/operations/{id}/fuel', name: 'api_operation_fuel_delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function clearFuel(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);
        $operation->clearFuel();
        $entityManager->flush();

        return new JsonResponse(['operation' => $operationPresenter->present($operation)]);
    }

    /** GET /api/operations/{id}/reconciliation-candidates : candidats de l'appariement manuel. */
    #[Route('/api/operations/{id}/reconciliation-candidates', name: 'api_operation_reconciliation_candidates', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function reconciliationCandidates(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ManualEntryReconciler $reconciler,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);
        if (!$operation->isAwaitingStatement()) {
            throw new BadRequestException('Seule une saisie manuelle en attente a des candidats de rapprochement.', 'not_a_manual_entry');
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (Operation $candidate): array => $operationPresenter->present($candidate),
                $reconciler->candidates($operation),
            ),
        ]);
    }

    /**
     * POST /api/operations/{id}/reconcile {"operationId": n} : appariement manuel d'une saisie
     * en attente avec une ligne de relevé ⇒ 200 {"operation": <ligne conservée>}.
     * Les valeurs du relevé font foi (date, montant, compte, libellé).
     */
    #[Route('/api/operations/{id}/reconcile', name: 'api_operation_reconcile', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reconcile(
        int $id,
        #[MapRequestPayload] ReconcileInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ManualEntryReconciler $reconciler,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $entry = $this->findOperation($entityManager, $household, $id);
        if (!$entry->isAwaitingStatement()) {
            throw new BadRequestException('Seule une saisie manuelle en attente peut être rapprochée.', 'not_a_manual_entry');
        }
        $line = $this->findOperation($entityManager, $household, (int) $input->operationId);
        if ($line->isAwaitingStatement()) {
            throw new BadRequestException('La cible est une saisie en attente, pas une ligne de relevé.', 'not_a_manual_entry');
        }
        if (null !== $line->getReconciledAt()) {
            throw new BadRequestException('Cette ligne de relevé est déjà rapprochée d’une saisie.', 'already_reconciled');
        }

        $merged = $reconciler->merge($entry, $line);

        return new JsonResponse(['operation' => $operationPresenter->present($merged)]);
    }

    #[Route('/api/operations/{id}/categorize', name: 'api_operation_categorize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function categorize(
        int $id,
        #[MapRequestPayload] CategorizeOperationInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ManualCategorizer $manualCategorizer,
        OperationPresenter $operationPresenter,
        RulePresenter $rulePresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $operation = $this->findOperation($entityManager, $household, $id);

        if (null === $input->categoryId) {
            // Retire la catégorie manuelle puis recatégorise automatiquement.
            $manualCategorizer->clear($operation);
            $entityManager->refresh($operation);

            return new JsonResponse([
                'operation' => $operationPresenter->present($operation),
                'updatedCount' => 0,
                'rule' => null,
            ]);
        }

        $category = $this->findCategory($entityManager, $household, $input->categoryId);
        $result = 'merchant' === $input->scope
            ? $manualCategorizer->assign($operation, $category, createRule: true, applyToSimilar: true)
            : $manualCategorizer->assign($operation, $category, createRule: false, applyToSimilar: false);
        $entityManager->refresh($operation);

        return new JsonResponse([
            'operation' => $operationPresenter->present($operation),
            'updatedCount' => $result->updatedCount,
            'rule' => null !== $result->rule ? $rulePresenter->present($result->rule, $this->ruleMatchCount($entityManager, $household, $result->rule)) : null,
        ]);
    }

    #[Route('/api/operations/{id}/duplicate', name: 'api_operation_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(
        int $id,
        #[MapRequestPayload] DuplicateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        OperationPresenter $operationPresenter,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);

        if ('distinct' === $input->action) {
            $operation->setDuplicateStatus(DuplicateStatus::ConfirmedDistinct);
            $operation->setDuplicateOf(null);
            $entityManager->flush();

            return new JsonResponse(['kept' => $operationPresenter->present($operation)]);
        }

        // merge : on conserve l'opération de référence (libellé PDF prioritaire, sinon duplicateOf).
        $reference = $operation->getDuplicateOf();
        $kept = ImportFormat::Pdf === $operation->getLabelSource() || null === $reference ? $operation : $reference;
        $removed = $kept === $operation ? $reference : $operation;
        if (null === $removed) {
            throw new BadRequestException('Cette opération n’est pas un doublon probable.', 'not_a_duplicate');
        }
        // Report de la catégorie manuelle et de la note sur l'opération conservée si elle n'en a pas.
        if (null === $kept->getCategory()
            && null !== $removed->getCategory()
            && CategorizationSource::Manual === $removed->getCategorizationSource()) {
            $kept->categorize($removed->getCategory(), CategorizationSource::Manual);
        }
        if (null === $kept->getNote()) {
            $kept->setNote($removed->getNote());
        }
        // Plein (ADR 0015) : reporté sur l'opération conservée si elle n'en a pas (et qu'elle est un débit).
        if (!$kept->hasFuel() && $removed->hasFuel() && $kept->isDebit()) {
            $kept->recordFuel((int) $removed->getFuelVolumeMl(), $removed->getFuelFullTank() ?? true, $removed->getOdometerKm());
        }
        // Remplissage d'AdBlue lié (ADR 0016) : passe sur l'opération conservée.
        $entityManager->getRepository(AdblueFill::class)->transferOperation($removed, $kept);
        $kept->setDuplicateStatus(DuplicateStatus::None);
        $kept->setDuplicateOf(null);
        $entityManager->remove($removed);
        $entityManager->flush();
        // L'opération supprimée pouvait porter une affectation véhicule : resynchronise le foyer.
        $vehicleAssigner->sync($householdProvider->get());

        return new JsonResponse(['kept' => $operationPresenter->present($kept)]);
    }

    private function findOperation(EntityManagerInterface $entityManager, Household $household, int $id): Operation
    {
        $operation = $entityManager->find(Operation::class, $id);
        if (!$operation instanceof Operation || (int) $operation->getAccount()->getHousehold()->getId() !== (int) $household->getId()) {
            throw new NotFoundException('Opération introuvable.');
        }

        return $operation;
    }

    private function findCategory(EntityManagerInterface $entityManager, Household $household, int $categoryId): Category
    {
        $category = $entityManager->getRepository(Category::class)->findOneBy(['id' => $categoryId, 'household' => $household]);
        if (!$category instanceof Category) {
            throw new ValidationException([['field' => 'categoryId', 'message' => 'Catégorie introuvable dans le foyer.']]);
        }

        return $category;
    }

    private function findVehicle(EntityManagerInterface $entityManager, Household $household, int $vehicleId): Vehicle
    {
        $vehicle = $entityManager->getRepository(Vehicle::class)->findOneBy(['id' => $vehicleId, 'household' => $household]);
        if (!$vehicle instanceof Vehicle) {
            throw new ValidationException([['field' => 'vehicleId', 'message' => 'Véhicule introuvable dans le foyer.']]);
        }

        return $vehicle;
    }

    private function ruleMatchCount(EntityManagerInterface $entityManager, Household $household, \App\Entity\CategorizationRule $rule): int
    {
        return (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM operation o JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND o.applied_rule_id = :rule',
            ['household' => $household->getId(), 'rule' => $rule->getId()],
        );
    }
}
