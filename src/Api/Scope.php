<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Household;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Périmètre des listes et agrégats (contrat docs/api.md) : `accountId[]` (répétable)
 * ou `memberId` (comptes dont la personne est titulaire). Absent ⇒ tout le foyer.
 */
final class Scope
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<int>|null ids de comptes du périmètre (null = tout le foyer)
     */
    public function accountIds(Request $request, Household $household): ?array
    {
        $connection = $this->entityManager->getConnection();
        $all = array_map(
            static fn (array $row): int => (int) $row['id'],
            $connection->fetchAllAssociative(
                'SELECT id FROM account WHERE household_id = :household',
                ['household' => $household->getId()],
            ),
        );

        $filter = null;
        $rawAccountIds = $request->query->all('accountId');
        if ([] !== $rawAccountIds) {
            $ids = [];
            foreach ($rawAccountIds as $raw) {
                if (!ctype_digit((string) $raw)) {
                    throw new BadRequestException('Paramètre accountId invalide.', 'invalid_query');
                }
                $ids[] = (int) $raw;
            }
            $filter = array_values(array_intersect($all, $ids));
        }

        $rawMemberId = $request->query->get('memberId');
        if (null !== $rawMemberId && '' !== $rawMemberId) {
            if (!ctype_digit((string) $rawMemberId)) {
                throw new BadRequestException('Paramètre memberId invalide.', 'invalid_query');
            }
            $memberId = (int) $rawMemberId;
            $held = array_map(
                static fn (array $row): int => (int) $row['account_id'],
                $connection->fetchAllAssociative(
                    'SELECT ah.account_id FROM account_holder ah
                     JOIN account a ON a.id = ah.account_id
                     WHERE ah.member_id = :member AND a.household_id = :household',
                    ['member' => $memberId, 'household' => $household->getId()],
                ),
            );
            $filter = null === $filter ? $held : array_values(array_intersect($filter, $held));
        }

        return $filter;
    }

    /**
     * Restreint un QueryBuilder (alias du compte donné) au périmètre.
     *
     * @param list<int>|null $accountIds
     */
    public function apply(QueryBuilder $qb, ?array $accountIds, string $accountAlias = 'a'): void
    {
        if (null !== $accountIds) {
            $qb->andWhere($accountAlias.'.id IN (:scopeAccountIds)')
                ->setParameter('scopeAccountIds', $accountIds, ArrayParameterType::INTEGER);
        }
    }
}
