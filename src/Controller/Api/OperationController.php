<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\Filter\OperationFilter;
use App\Api\HouseholdProvider;
use App\Api\Input\BulkCategorizeInput;
use App\Api\Input\CategorizeOperationInput;
use App\Api\Input\DuplicateInput;
use App\Api\Input\OperationPatchInput;
use App\Api\NotFoundException;
use App\Api\Pagination;
use App\Api\Presenter\OperationPresenter;
use App\Api\Presenter\RulePresenter;
use App\Api\Scope;
use App\Api\ValidationException;
use App\Categorization\ManualCategorizer;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    #[Route('/api/operations/{id}', name: 'api_operation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, HouseholdProvider $householdProvider, EntityManagerInterface $entityManager, OperationPresenter $operationPresenter): JsonResponse
    {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);

        return new JsonResponse($operationPresenter->present($operation));
    }

    #[Route('/api/operations/{id}', name: 'api_operation_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        #[MapRequestPayload] OperationPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $operation = $this->findOperation($entityManager, $householdProvider->get(), $id);
        $operation->setNote($input->note);
        $entityManager->flush();

        return new JsonResponse($operationPresenter->present($operation));
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

    private function ruleMatchCount(EntityManagerInterface $entityManager, Household $household, \App\Entity\CategorizationRule $rule): int
    {
        return (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM operation o JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND o.applied_rule_id = :rule',
            ['household' => $household->getId(), 'rule' => $rule->getId()],
        );
    }
}
