<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Input\BudgetCreateInput;
use App\Api\Input\BudgetPatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\BudgetPresenter;
use App\Api\ValidationException;
use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Member;
use App\Enum\BudgetScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BudgetController extends AbstractController
{
    #[Route('/api/budgets', name: 'api_budgets', methods: ['GET'])]
    public function list(
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        BudgetPresenter $presenter,
    ): JsonResponse {
        $budgets = $entityManager->getRepository(Budget::class)->createQueryBuilder('b')
            ->join('b.household', 'h')
            ->where('h = :household')
            ->orderBy('b.id', 'ASC')
            ->setParameter('household', $householdProvider->get())
            ->getQuery()->getResult();

        return new JsonResponse(array_map(static fn (Budget $budget): array => $presenter->present($budget), $budgets));
    }

    #[Route('/api/budgets', name: 'api_budget_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] BudgetCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        BudgetPresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $budget = new Budget(
            $household,
            $this->findCategory($entityManager, $household, (int) $input->categoryId),
            (int) $input->monthlyAmount,
            BudgetScope::from((string) $input->scope),
            new \DateTimeImmutable((string) $input->startMonth),
        );
        $this->applyTarget($budget, $entityManager, $household, $input->memberId, $input->accountId);
        if (null !== $input->endMonth) {
            $budget->setEndMonth(new \DateTimeImmutable($input->endMonth));
        }
        $this->assertValid($validator, $budget);
        $entityManager->persist($budget);
        $entityManager->flush();

        return new JsonResponse($presenter->present($budget), Response::HTTP_CREATED);
    }

    #[Route('/api/budgets/{id}', name: 'api_budget_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        #[MapRequestPayload] BudgetPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        BudgetPresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $budget = $this->find($entityManager, $household, $id);

        if (null !== $input->categoryId) {
            $budget->setCategory($this->findCategory($entityManager, $household, $input->categoryId));
        }
        if (null !== $input->monthlyAmount) {
            $budget->setMonthlyAmount($input->monthlyAmount);
        }
        if (null !== $input->scope) {
            $budget->setScope(BudgetScope::from($input->scope));
        }
        if (null !== $input->memberId || null !== $input->accountId) {
            $this->applyTarget($budget, $entityManager, $household, $input->memberId ?? ($budget->getMember()?->getId()), $input->accountId ?? ($budget->getAccount()?->getId()));
        }
        if (null !== $input->startMonth) {
            $budget->setStartMonth(new \DateTimeImmutable($input->startMonth));
        }
        if (null !== $input->endMonth) {
            $budget->setEndMonth(new \DateTimeImmutable($input->endMonth));
        }
        $this->assertValid($validator, $budget);
        $entityManager->flush();

        return new JsonResponse($presenter->present($budget));
    }

    #[Route('/api/budgets/{id}', name: 'api_budget_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, HouseholdProvider $householdProvider, EntityManagerInterface $entityManager): Response
    {
        $budget = $this->find($entityManager, $householdProvider->get(), $id);
        $entityManager->remove($budget);
        $entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function find(EntityManagerInterface $entityManager, Household $household, int $id): Budget
    {
        $budget = $entityManager->getRepository(Budget::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$budget instanceof Budget) {
            throw new NotFoundException('Budget introuvable.');
        }

        return $budget;
    }

    private function findCategory(EntityManagerInterface $entityManager, Household $household, int $categoryId): Category
    {
        $category = $entityManager->getRepository(Category::class)->findOneBy(['id' => $categoryId, 'household' => $household]);
        if (!$category instanceof Category) {
            throw new ValidationException([['field' => 'categoryId', 'message' => 'Catégorie introuvable dans le foyer.']]);
        }

        return $category;
    }

    private function applyTarget(Budget $budget, EntityManagerInterface $entityManager, Household $household, ?int $memberId, ?int $accountId): void
    {
        if (null !== $memberId) {
            $member = $entityManager->getRepository(Member::class)->findOneBy(['id' => $memberId, 'household' => $household]);
            if (!$member instanceof Member) {
                throw new ValidationException([['field' => 'memberId', 'message' => 'Membre introuvable dans le foyer.']]);
            }
            $budget->setMember($member);
        }
        if (null !== $accountId) {
            $account = $entityManager->getRepository(Account::class)->findOneBy(['id' => $accountId, 'household' => $household]);
            if (!$account instanceof Account) {
                throw new ValidationException([['field' => 'accountId', 'message' => 'Compte introuvable dans le foyer.']]);
            }
            $budget->setAccount($account);
        }
    }

    /** Validation de l'entité (callback validateScope) ⇒ 422 avec violations mappées sur les champs d'entrée. */
    private function assertValid(ValidatorInterface $validator, Budget $budget): void
    {
        $violations = $validator->validate($budget);
        if (0 === count($violations)) {
            return;
        }
        $mapped = [];
        foreach ($violations as $violation) {
            $field = match ((string) $violation->getPropertyPath()) {
                'member' => 'memberId',
                'account' => 'accountId',
                'category' => 'categoryId',
                default => (string) $violation->getPropertyPath(),
            };
            $mapped[] = ['field' => $field, 'message' => $violation->getMessage()];
        }
        throw new ValidationException($mapped);
    }
}
