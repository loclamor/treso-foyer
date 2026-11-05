<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Input\RuleCreateInput;
use App\Api\Input\RulePatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\OperationPresenter;
use App\Api\Presenter\RulePresenter;
use App\Api\Query\StatsQuery;
use App\Api\ValidationException;
use App\Categorization\CategorizeOptions;
use App\Categorization\Categorizer;
use App\Entity\Account;
use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class RuleController extends AbstractController
{
    #[Route('/api/rules', name: 'api_rules', methods: ['GET'])]
    public function list(
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        RulePresenter $rulePresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $rules = $entityManager->getRepository(CategorizationRule::class)->findBy(['household' => $household]);
        usort($rules, static fn (CategorizationRule $a, CategorizationRule $b): int => [$b->getPriority(), (int) $a->getId()] <=> [$a->getPriority(), (int) $b->getId()]);
        $counts = $statsQuery->ruleMatchCounts($household);

        return new JsonResponse(array_map(
            static fn (CategorizationRule $rule): array => $rulePresenter->present($rule, $counts[(int) $rule->getId()] ?? 0),
            $rules,
        ));
    }

    #[Route('/api/rules', name: 'api_rule_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] RuleCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        RulePresenter $rulePresenter,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $household = $householdProvider->get();
        $rule = $this->buildRule($household, $entityManager, $input->categoryId, $input->matchField, $input->matchType, $input->pattern, $input);
        $rule->setDirection(RuleDirection::from($input->direction));
        if (null !== $input->priority) {
            $rule->setPriority($input->priority);
        }
        if (null !== $input->enabled) {
            $rule->setEnabled($input->enabled);
        }
        $this->assertValidRegex($rule);
        $entityManager->persist($rule);
        $entityManager->flush();
        // La règle créée peut désigner un véhicule : resynchronise l'axe véhicule.
        $vehicleAssigner->sync($household);

        return new JsonResponse($rulePresenter->present($rule, 0), Response::HTTP_CREATED);
    }

    #[Route('/api/rules/{id}', name: 'api_rule_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] RulePatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        RulePresenter $rulePresenter,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $household = $householdProvider->get();
        $rule = $this->findRule($entityManager, $household, $id);

        if (null !== $input->categoryId) {
            $rule->setCategory($this->findCategory($entityManager, $household, $input->categoryId));
        }
        if (null !== $input->matchField) {
            $rule->setMatchField(RuleMatchField::from($input->matchField));
        }
        if (null !== $input->matchType) {
            $rule->setMatchType(RuleMatchType::from($input->matchType));
        }
        if (null !== $input->pattern) {
            $rule->setPattern($input->pattern);
        }
        if (null !== $input->direction) {
            $rule->setDirection(RuleDirection::from($input->direction));
        }
        if (null !== $input->amountMin) {
            $rule->setAmountMin($input->amountMin);
        }
        if (null !== $input->amountMax) {
            $rule->setAmountMax($input->amountMax);
        }
        if (null !== $input->accountId) {
            $account = $entityManager->getRepository(Account::class)->findOneBy(['id' => $input->accountId, 'household' => $household]);
            if (!$account instanceof Account) {
                throw new ValidationException([['field' => 'accountId', 'message' => 'Compte introuvable dans le foyer.']]);
            }
            $rule->setAccount($account);
        }
        if (null !== $input->priority) {
            $rule->setPriority($input->priority);
        }
        if (null !== $input->enabled) {
            $rule->setEnabled($input->enabled);
        }
        // vehicleId : la clé présente dans le corps (même null) est appliquée, absente = inchangé.
        if (array_key_exists('vehicleId', $request->toArray())) {
            $rule->setVehicle(null === $input->vehicleId ? null : $this->findVehicle($entityManager, $household, $input->vehicleId));
        }
        $this->assertValidRegex($rule);
        $entityManager->flush();
        // La règle modifiée peut désigner un véhicule (ou plus) : resynchronise l'axe véhicule.
        $vehicleAssigner->sync($household);

        $counts = $statsQuery->ruleMatchCounts($household);

        return new JsonResponse($rulePresenter->present($rule, $counts[(int) $rule->getId()] ?? 0));
    }

    #[Route('/api/rules/{id}', name: 'api_rule_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        Categorizer $categorizer,
        VehicleAssigner $vehicleAssigner,
    ): Response {
        $household = $householdProvider->get();
        $rule = $this->findRule($entityManager, $household, $id);

        // Décatégorise les opérations (non manuelles) catégorisées par cette règle.
        $operations = $entityManager->getRepository(Operation::class)->findBy(['appliedRule' => $rule]);
        foreach ($operations as $operation) {
            if (null !== $operation->getAppliedRule() && (int) $operation->getAppliedRule()->getId() === (int) $rule->getId()) {
                $operation->uncategorize();
            }
        }
        $entityManager->remove($rule);
        $entityManager->flush();

        // Relance la catégorisation automatique sur les opérations libérées (par lots).
        $fresh = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.category IS NULL')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $household)
            ->getQuery()->toIterable();
        $categorizer->categorize($fresh, new CategorizeOptions(onlyUncategorized: true));
        // La règle supprimée pouvait désigner un véhicule : resynchronise l'axe véhicule.
        $entityManager->flush();
        $vehicleAssigner->sync($household);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/rules/preview', name: 'api_rule_preview', methods: ['POST'])]
    public function preview(
        #[MapRequestPayload] RulePatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        OperationPresenter $operationPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        if (null === $input->categoryId || null === $input->matchField || null === $input->matchType || null === $input->pattern) {
            throw new ValidationException([['field' => 'rule', 'message' => 'categoryId, matchField, matchType et pattern sont obligatoires pour un aperçu.']]);
        }
        $rule = $this->buildRule($household, $entityManager, $input->categoryId, $input->matchField, $input->matchType, $input->pattern, $input);
        $rule->setDirection(RuleDirection::from($input->direction ?? 'any'));
        $this->assertValidRegex($rule);

        $matchCount = 0;
        $conflicts = 0;
        $manualCount = 0;
        $sample = [];
        $operations = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $household)
            ->getQuery()->toIterable();
        foreach ($operations as $operation) {
            \assert($operation instanceof Operation);
            if (!$rule->matches($operation)) {
                continue;
            }
            ++$matchCount;
            $category = $operation->getCategory();
            $source = $operation->getCategorizationSource();
            if (CategorizationSource::Manual === $source) {
                ++$manualCount;
            } elseif (null !== $category && (int) $category->getId() !== (int) $rule->getCategory()->getId()) {
                ++$conflicts;
            }
            if (count($sample) < 10) {
                $sample[] = $operationPresenter->present($operation);
            }
        }

        return new JsonResponse([
            'matchCount' => $matchCount,
            'conflicts' => $conflicts,
            'manualCount' => $manualCount,
            'sample' => $sample,
        ]);
    }

    /**
     * Construit une règle (persistée ou non selon l'appelant) à partir des champs du DTO.
     */
    private function buildRule(
        Household $household,
        EntityManagerInterface $entityManager,
        int $categoryId,
        string $matchField,
        string $matchType,
        string $pattern,
        RuleCreateInput|RulePatchInput $input,
    ): CategorizationRule {
        $rule = new CategorizationRule(
            $household,
            $this->findCategory($entityManager, $household, $categoryId),
            RuleMatchField::from($matchField),
            RuleMatchType::from($matchType),
            $pattern,
        );
        $rule->setDirection(RuleDirection::from($input->direction ?? 'any'));
        if (null !== $input->amountMin) {
            $rule->setAmountMin($input->amountMin);
        }
        if (null !== $input->amountMax) {
            $rule->setAmountMax($input->amountMax);
        }
        if (null !== $input->accountId) {
            $account = $entityManager->getRepository(Account::class)->findOneBy(['id' => $input->accountId, 'household' => $household]);
            if (!$account instanceof Account) {
                throw new ValidationException([['field' => 'accountId', 'message' => 'Compte introuvable dans le foyer.']]);
            }
            $rule->setAccount($account);
        }
        if (null !== $input->priority) {
            $rule->setPriority($input->priority);
        }
        if (null !== $input->enabled) {
            $rule->setEnabled($input->enabled);
        }
        if (null !== $input->vehicleId) {
            $rule->setVehicle($this->findVehicle($entityManager, $household, $input->vehicleId));
        }

        return $rule;
    }

    /** Regex invalide ⇒ 422 (la règle ne matcherait jamais rien). */
    private function assertValidRegex(CategorizationRule $rule): void
    {
        if (RuleMatchType::Regex !== $rule->getMatchType()) {
            return;
        }
        $pattern = '~'.str_replace('~', '\\\\~', $rule->getPattern()).'~iu';
        if (false === @preg_match($pattern, '')) {
            throw new ValidationException([['field' => 'pattern', 'message' => 'Expression régulière invalide.']]);
        }
    }

    private function findRule(EntityManagerInterface $entityManager, Household $household, int $id): CategorizationRule
    {
        $rule = $entityManager->getRepository(CategorizationRule::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$rule instanceof CategorizationRule) {
            throw new NotFoundException('Règle introuvable.');
        }

        return $rule;
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
}
