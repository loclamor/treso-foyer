<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ConflictException;
use App\Api\HouseholdProvider;
use App\Api\Input\AccountPatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\AccountPresenter;
use App\Api\Query\StatsQuery;
use App\Api\ValidationException;
use App\Entity\Account;
use App\Entity\Member;
use App\Enum\AccountType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/api/accounts', name: 'api_accounts', methods: ['GET'])]
    public function list(
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        AccountPresenter $accountPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accounts = $entityManager->getRepository(Account::class)->findBy(['household' => $household], ['id' => 'ASC']);
        $stats = $statsQuery->accountStats($household);

        return new JsonResponse(array_map(
            static fn (Account $account): array => $accountPresenter->present($account, $stats[(int) $account->getId()] ?? []),
            $accounts,
        ));
    }

    #[Route('/api/accounts/{id}', name: 'api_account_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        #[MapRequestPayload] AccountPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        AccountPresenter $accountPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $account = $entityManager->getRepository(Account::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$account instanceof Account) {
            throw new NotFoundException('Compte introuvable.');
        }

        if (null !== $input->alias) {
            $conflict = $entityManager->getRepository(Account::class)->findOneBy(['household' => $household, 'alias' => $input->alias]);
            if ($conflict instanceof Account && (int) $conflict->getId() !== (int) $account->getId()) {
                throw new ConflictException('Cet alias est déjà utilisé par un autre compte.', 'alias_exists');
            }
            $account->setAlias($input->alias);
        }
        if (null !== $input->label) {
            $account->setLabel($input->label);
        }
        if (null !== $input->type) {
            $account->setType(AccountType::from($input->type));
        }
        if (null !== $input->closedAt) {
            $account->setClosedAt('' === $input->closedAt ? null : new \DateTimeImmutable($input->closedAt));
        }
        if (null !== $input->holderIds) {
            $members = [];
            if ([] !== $input->holderIds) {
                $members = $entityManager->getRepository(Member::class)->findBy(['household' => $household, 'id' => $input->holderIds]);
            }
            if (count($members) !== count($input->holderIds)) {
                throw new ValidationException([['field' => 'holderIds', 'message' => 'Titulaire introuvable dans le foyer.']]);
            }
            foreach ($account->getHolders()->toArray() as $holder) {
                $account->removeHolder($holder);
            }
            foreach ($members as $member) {
                $account->addHolder($member);
            }
        }
        $entityManager->flush();

        $stats = $statsQuery->accountStats($household);

        return new JsonResponse($accountPresenter->present($account, $stats[(int) $account->getId()] ?? []));
    }
}
